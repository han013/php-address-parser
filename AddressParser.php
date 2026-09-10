<?php

declare(strict_types=1);

namespace hanhan\AddressParser;

final class AddressParser
{
    private const PROVINCE_SUFFIX = ["省", "市", "自治区", "特别行政区"];
    private const CITY_SUFFIX = ["市", "地区", "自治州", "盟"];
    private const DISTRICT_SUFFIX = ["区", "县", "旗", "自治县", "自治旗", "市"];
    private const TOWNSHIP_SUFFIX = ["街道", "镇", "乡", "民族乡", "苏木", "街道办事处", "地区"];
    private const GENERIC_CITY_NAMES = ["市辖区", "县", "城区", "矿区", "郊区"];
    private const GENERIC_TOWNSHIP_NAMES = ["街道", "镇", "乡", "地区"];
    private const DIRECT_CONTROLLED_MUNICIPALITIES = ["北京市", "上海市", "天津市", "重庆市"];
    private const TOWNSHIP_AMBIGUOUS_SHORTS = ["口岸"];

    private string $regionDir;
    private array $pcd = [];
    private array $landmarks = [];
    private array $townshipIndex = [];
    private array $provinceCodeByName = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $townshipChunkCache = [];

    public function __construct(?string $regionDir = null)
    {
        $this->regionDir = $regionDir ?? __DIR__ . "/data/region";
        $this->loadBaseData();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function parse(string $rawAddress, array $options = []): array
    {
        $text = $this->normalizeText($rawAddress);
        $defaultLandmarks = $this->landmarks;
        $extraLandmarks = is_array($options["landmarks"] ?? null) ? $options["landmarks"] : [];
        $landmarks = $this->mergeLandmarks($defaultLandmarks, $extraLandmarks);

        $result = [
            "raw" => $rawAddress,
            "normalized" => $text,
            "province" => "",
            "city" => "",
            "district" => "",
            "township" => "",
            "landmark" => "",
            "landmarkType" => "",
            "confidence" => 0.0,
        ];
        if ($text === "") {
            return $result;
        }

        foreach ($landmarks as $mark) {
            $aliases = $this->toAliasSet((string)($mark["name"] ?? ""), $mark["aliases"] ?? [], []);
            $matched = $this->hitAny($text, $aliases);
            if ($matched !== "") {
                $result["province"] = (string)($mark["province"] ?? "");
                $result["city"] = (string)($mark["city"] ?? "");
                $result["district"] = (string)($mark["district"] ?? "");
                $result["landmark"] = (string)($mark["name"] ?? "");
                $result["landmarkType"] = (string)($mark["type"] ?? "标志地区");
                $result["confidence"] = 0.98;
                return $result;
            }
        }

        $divisionTree = is_array($options["divisionTree"] ?? null) && count($options["divisionTree"]) > 0
            ? $options["divisionTree"]
            : $this->pcd;

        [$matchedProvince, $matchedCity, $matchedDistrict] = $this->matchProvinceCityDistrict($text, $divisionTree);

        if (!empty($matchedProvince["name"])) {
            $result["province"] = (string)$matchedProvince["name"];
        }
        if (!empty($matchedCity["name"])) {
            $result["city"] = (string)$matchedCity["name"];
        }
        if (!empty($matchedDistrict["name"])) {
            $result["district"] = (string)$matchedDistrict["name"];
        }

        if ($result["city"] === "" && in_array($result["province"], self::DIRECT_CONTROLLED_MUNICIPALITIES, true)) {
            $result["city"] = $result["province"];
        }

        $townships = is_array($options["townships"] ?? null) ? $options["townships"] : [];
        $entries = $this->buildTownshipSearchEntries($townships);
        $matchedTownship = $this->matchTownship($text, $entries, [
            "province" => $result["province"],
            "city" => $result["city"],
            "district" => $result["district"],
        ]);
        if (!empty($matchedTownship)) {
            $result["township"] = (string)($matchedTownship["name"] ?? "");
            if ($result["province"] === "") {
                $result["province"] = (string)($matchedTownship["province"] ?? "");
            }
            if ($result["city"] === "") {
                $result["city"] = (string)($matchedTownship["city"] ?? "");
            }
            if ($result["district"] === "") {
                $result["district"] = (string)($matchedTownship["district"] ?? "");
            }
        }

        $score = 0.0;
        $score += $result["province"] !== "" ? 0.35 : 0.0;
        $score += $result["city"] !== "" ? 0.35 : 0.0;
        $score += $result["district"] !== "" ? 0.2 : 0.0;
        $score += $result["township"] !== "" ? 0.1 : 0.0;
        $result["confidence"] = round($score, 2);

        return $result;
    }

    /**
     * 分片加载乡镇数据，再做二次解析。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function parseWithChunkTownships(string $rawAddress, array $options = []): array
    {
        $base = $this->parse($rawAddress, $options);
        $text = $this->normalizeText($rawAddress);
        if ($text === "" || $base["township"] !== "") {
            return $base;
        }

        $provinceCodes = [];
        if ($base["province"] !== "" && isset($this->provinceCodeByName[$base["province"]])) {
            $provinceCodes[] = $this->provinceCodeByName[$base["province"]];
        } else {
            $provinceCodes = array_slice($this->inferProvinceCodesFromTownshipIndex($text), 0, 3);
        }
        if (count($provinceCodes) === 0) {
            return $base;
        }

        $chunkTownships = [];
        foreach ($provinceCodes as $code) {
            $chunkTownships = array_merge($chunkTownships, $this->loadTownshipsByProvinceCode((string)$code));
        }

        $newOptions = $options;
        $extraTownships = is_array($options["townships"] ?? null) ? $options["townships"] : [];
        $newOptions["townships"] = array_merge($chunkTownships, $extraTownships);

        $parsed = $this->parse($rawAddress, $newOptions);
        if ($parsed["township"] !== "" || $parsed["confidence"] >= $base["confidence"]) {
            return $parsed;
        }
        return $base;
    }

    private function loadBaseData(): void
    {
        $this->pcd = $this->readJson("pcd.json");
        $this->landmarks = $this->readJson("landmarks.json");
        $this->townshipIndex = $this->readJson("township-index.json");

        foreach ($this->pcd as $p) {
            $name = (string)($p["name"] ?? "");
            $code = (string)($p["code"] ?? "");
            if ($name === "" || $code === "") {
                continue;
            }
            $this->provinceCodeByName[$name] = $code;
            if ($this->endsWith($name, "省") || $this->endsWith($name, "市")) {
                $this->provinceCodeByName[$this->dropSuffix($name, [mb_substr($name, -1)])] = $code;
            }
            if ($name === "内蒙古自治区") {
                $this->provinceCodeByName["内蒙古"] = $code;
            }
            if ($name === "新疆维吾尔自治区") {
                $this->provinceCodeByName["新疆"] = $code;
            }
            if ($name === "广西壮族自治区") {
                $this->provinceCodeByName["广西"] = $code;
            }
            if ($name === "宁夏回族自治区") {
                $this->provinceCodeByName["宁夏"] = $code;
            }
            if ($name === "西藏自治区") {
                $this->provinceCodeByName["西藏"] = $code;
            }
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function matchProvinceCityDistrict(string $text, array $provinces): array
    {
        $matchedProvince = [];
        $matchedCity = [];
        $matchedDistrict = [];

        foreach ($provinces as $p) {
            $aliases = $this->buildProvinceAliases($p);
            if ($this->hitAny($text, $aliases) !== "") {
                $matchedProvince = $p;
                break;
            }
        }

        if (!empty($matchedProvince)) {
            $cities = is_array($matchedProvince["cities"] ?? null) ? $matchedProvince["cities"] : [];
            $bestCity = $this->pickBestAliasMatch($text, $cities, "city");
            if (!empty($bestCity)) {
                $matchedCity = $bestCity;
            }
            if (!empty($matchedCity)) {
                $bestDistrict = $this->pickBestAliasMatch(
                    $text,
                    is_array($matchedCity["districts"] ?? null) ? $matchedCity["districts"] : [],
                    "district"
                );
                if (!empty($bestDistrict)) {
                    $matchedDistrict = $bestDistrict;
                }
            } else {
                $best = $this->pickBestCityDistrictAcrossProvinces($text, [$matchedProvince]);
                if (!empty($best["city"])) {
                    $matchedCity = $best["city"];
                    $matchedDistrict = $best["district"];
                }
            }
        } else {
            $best = $this->pickBestCityDistrictAcrossProvinces($text, $provinces);
            if (!empty($best["province"])) {
                $matchedProvince = $best["province"];
                $matchedCity = $best["city"];
                $matchedDistrict = $best["district"];
            }
        }

        if (empty($matchedDistrict) && !empty($matchedCity)) {
            $bestDistrict = $this->pickBestAliasMatch(
                $text,
                is_array($matchedCity["districts"] ?? null) ? $matchedCity["districts"] : [],
                "district"
            );
            if (!empty($bestDistrict)) {
                $matchedDistrict = $bestDistrict;
            }
        }

        return [$matchedProvince, $matchedCity, $matchedDistrict];
    }

    /**
     * 无省份时，城市/区县统一按最长别名命中，避免短别名误伤（如「乌兰」误匹配「乌兰察布」）。
     *
     * @param array<int, array<string, mixed>> $provinces
     * @return array{province: array<string, mixed>, city: array<string, mixed>, district: array<string, mixed>}
     */
    private function pickBestCityDistrictAcrossProvinces(string $text, array $provinces): array
    {
        $best = [
            "province" => [],
            "city" => [],
            "district" => [],
            "len" => 0,
            "level" => 0,
        ];

        foreach ($provinces as $p) {
            foreach (($p["cities"] ?? []) as $c) {
                $cityHit = $this->hitAny($text, $this->buildCityAliases($c));
                if ($cityHit !== "") {
                    $len = mb_strlen($cityHit);
                    // level: city=2 > district=1，同长度时优先城市
                    if ($len > $best["len"] || ($len === $best["len"] && 2 > $best["level"])) {
                        $best = [
                            "province" => $p,
                            "city" => $c,
                            "district" => [],
                            "len" => $len,
                            "level" => 2,
                        ];
                    }
                }

                foreach (($c["districts"] ?? []) as $d) {
                    $districtHit = $this->hitAny($text, $this->buildDistrictAliases($d));
                    if ($districtHit === "") {
                        continue;
                    }
                    $len = mb_strlen($districtHit);
                    if ($len > $best["len"] || ($len === $best["len"] && 1 > $best["level"])) {
                        $best = [
                            "province" => $p,
                            "city" => $c,
                            "district" => $d,
                            "len" => $len,
                            "level" => 1,
                        ];
                    }
                }
            }
        }

        return [
            "province" => $best["province"],
            "city" => $best["city"],
            "district" => $best["district"],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param "city"|"district" $type
     * @return array<string, mixed>
     */
    private function pickBestAliasMatch(string $text, array $items, string $type): array
    {
        $best = [];
        $bestLen = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $aliases = $type === "city"
                ? $this->buildCityAliases($item)
                : $this->buildDistrictAliases($item);
            $hit = $this->hitAny($text, $aliases);
            if ($hit === "") {
                continue;
            }
            $len = mb_strlen($hit);
            if ($len > $bestLen) {
                $best = $item;
                $bestLen = $len;
            }
        }
        return $best;
    }

    private function normalizeText(string $text): string
    {
        $out = trim($text);
        $out = preg_replace('/\s+/u', '', $out) ?? "";
        $out = preg_replace('/[，,。；;：:、\-]/u', '', $out) ?? "";
        return $out;
    }

    private function dropSuffix(string $name, array $suffixList): string
    {
        $result = $name;
        foreach ($suffixList as $suffix) {
            if ($suffix !== "" && $this->endsWith($result, (string)$suffix)) {
                $result = mb_substr($result, 0, mb_strlen($result) - mb_strlen((string)$suffix));
            }
        }
        return $result;
    }

    private function toAliasSet(string $name, array $aliases, array $suffixList): array
    {
        $items = [];
        foreach (array_merge([$name], $aliases) as $item) {
            $s = (string)$item;
            if ($s !== "") {
                $items[$s] = true;
            }
        }
        foreach (array_keys($items) as $item) {
            $short = $this->dropSuffix($item, $suffixList);
            if ($short !== "") {
                $items[$short] = true;
            }
        }
        $result = array_keys($items);
        usort(
            $result,
            static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a)
        );
        return $result;
    }

    private function hitAny(string $text, array $words): string
    {
        foreach ($words as $word) {
            $w = (string)$word;
            if ($w !== "" && $text === $w) {
                return $w;
            }
        }
        foreach ($words as $word) {
            $w = (string)$word;
            if ($w !== "" && mb_strpos($text, $w) !== false) {
                return $w;
            }
        }
        return "";
    }

    private function sanitizeAliases(array $words, int $minLen, array $blacklist): array
    {
        $black = array_fill_keys($blacklist, true);
        return array_values(array_filter($words, static function ($w) use ($minLen, $black): bool {
            $s = (string)$w;
            return $s !== "" && mb_strlen($s) >= $minLen && !isset($black[$s]);
        }));
    }

    private function buildProvinceAliases(array $p): array
    {
        $name = (string)($p["name"] ?? "");
        $aliases = is_array($p["aliases"] ?? null) ? $p["aliases"] : [];
        if (in_array($name, self::DIRECT_CONTROLLED_MUNICIPALITIES, true)) {
            $aliases[] = $this->dropSuffix($name, ["市"]);
        }
        if ($name === "内蒙古自治区") {
            $aliases[] = "内蒙古";
        } elseif ($name === "新疆维吾尔自治区") {
            $aliases[] = "新疆";
        } elseif ($name === "广西壮族自治区") {
            $aliases[] = "广西";
        } elseif ($name === "宁夏回族自治区") {
            $aliases[] = "宁夏";
        } elseif ($name === "西藏自治区") {
            $aliases[] = "西藏";
        } elseif ($name === "香港特别行政区") {
            $aliases[] = "香港";
        } elseif ($name === "澳门特别行政区") {
            $aliases[] = "澳门";
        }
        return $this->toAliasSet($name, $aliases, self::PROVINCE_SUFFIX);
    }

    private function buildCityAliases(array $c): array
    {
        $name = (string)($c["name"] ?? "");
        $aliases = is_array($c["aliases"] ?? null) ? $c["aliases"] : [];
        $all = $this->toAliasSet($name, $aliases, self::CITY_SUFFIX);
        return $this->sanitizeAliases($all, 2, self::GENERIC_CITY_NAMES);
    }

    private function buildDistrictAliases(array $d): array
    {
        $name = (string)($d["name"] ?? "");
        $aliases = is_array($d["aliases"] ?? null) ? $d["aliases"] : [];
        $all = $this->toAliasSet($name, $aliases, self::DISTRICT_SUFFIX);
        return $this->sanitizeAliases($all, 2, []);
    }

    private function buildTownshipAliases(array $t): array
    {
        $name = (string)($t["name"] ?? "");
        $aliases = is_array($t["aliases"] ?? null) ? $t["aliases"] : [];
        $all = $this->toAliasSet($name, $aliases, self::TOWNSHIP_SUFFIX);
        $blacklist = array_merge(self::GENERIC_TOWNSHIP_NAMES, self::TOWNSHIP_AMBIGUOUS_SHORTS);
        return $this->sanitizeAliases($all, 2, $blacklist);
    }

    private function buildTownshipSearchEntries(array $townships): array
    {
        $out = [];
        foreach ($townships as $t) {
            if (!is_array($t)) {
                continue;
            }
            $t["_aliases"] = $this->buildTownshipAliases($t);
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, string> $region
     * @return array<string, mixed>
     */
    private function matchTownship(string $text, array $entries, array $region): array
    {
        if (count($entries) === 0) {
            return [];
        }
        $hasProvince = ($region["province"] ?? "") !== "";
        $hasCity = ($region["city"] ?? "") !== "";
        $hasDistrict = ($region["district"] ?? "") !== "";

        $scoped = $entries;
        if ($hasProvince) {
            $scoped = array_values(array_filter($scoped, static function (array $t) use ($region): bool {
                return empty($t["province"]) || $t["province"] === $region["province"];
            }));
        }
        if ($hasCity) {
            $scoped = array_values(array_filter($scoped, static function (array $t) use ($region): bool {
                return empty($t["city"]) || $t["city"] === $region["city"];
            }));
        }
        if ($hasDistrict) {
            $scoped = array_values(array_filter($scoped, static function (array $t) use ($region): bool {
                return empty($t["district"]) || $t["district"] === $region["district"];
            }));
        }

        if (($hasProvince || $hasCity || $hasDistrict) && count($scoped) === 0) {
            return [];
        }

        $base = count($scoped) > 0 ? $scoped : $entries;
        $preferDistrict = array_values(array_filter($base, static function (array $t) use ($region): bool {
            if (($region["district"] ?? "") === "" || ($t["district"] ?? "") !== $region["district"]) {
                return false;
            }
            if (($region["city"] ?? "") !== "" && !empty($t["city"]) && $t["city"] !== $region["city"]) {
                return false;
            }
            if (($region["province"] ?? "") !== "" && !empty($t["province"]) && $t["province"] !== $region["province"]) {
                return false;
            }
            return true;
        }));
        $preferCity = array_values(array_filter($base, static function (array $t) use ($region): bool {
            if (($region["city"] ?? "") === "" || ($t["city"] ?? "") !== $region["city"]) {
                return false;
            }
            if (($region["province"] ?? "") !== "" && !empty($t["province"]) && $t["province"] !== $region["province"]) {
                return false;
            }
            return true;
        }));

        foreach ([$preferDistrict, $preferCity, $base] as $bucket) {
            if (count($bucket) === 0) {
                continue;
            }
            foreach ($bucket as $item) {
                foreach (($item["_aliases"] ?? []) as $alias) {
                    if ($alias === $text) {
                        return $item;
                    }
                }
            }
            $best = [];
            $bestLen = 0;
            foreach ($bucket as $item) {
                foreach (($item["_aliases"] ?? []) as $alias) {
                    $alias = (string)$alias;
                    if ($alias !== "" && mb_strpos($text, $alias) !== false && mb_strlen($alias) > $bestLen) {
                        $best = $item;
                        $bestLen = mb_strlen($alias);
                    }
                }
            }
            if (!empty($best)) {
                return $best;
            }
        }
        return [];
    }

    private function inferProvinceCodesFromTownshipIndex(string $text): array
    {
        $matched = [];
        foreach ($this->townshipIndex as $name => $rows) {
            $nameStr = (string)$name;
            $short = $this->dropSuffix($nameStr, self::TOWNSHIP_SUFFIX);
            $hitByFull = $nameStr !== "" && mb_strpos($text, $nameStr) !== false;
            $hitByShort = $short !== ""
                && mb_strlen($short) >= 2
                && mb_strpos($text, $short) !== false
                && !(in_array($short, self::TOWNSHIP_AMBIGUOUS_SHORTS, true) && !$hitByFull);
            if ($hitByFull || $hitByShort) {
                $matched[] = ["name" => $nameStr, "rows" => is_array($rows) ? $rows : []];
            }
        }

        usort($matched, static function (array $a, array $b): int {
            return mb_strlen((string)$b["name"]) <=> mb_strlen((string)$a["name"]);
        });

        $codeSet = [];
        foreach (array_slice($matched, 0, 8) as $m) {
            foreach (($m["rows"] ?? []) as $r) {
                $code = (string)($r["provinceCode"] ?? "");
                if ($code !== "") {
                    $codeSet[$code] = true;
                }
            }
        }
        return array_keys($codeSet);
    }

    /**
     * @param array<int, array<string, mixed>> $defaultLandmarks
     * @param array<int, array<string, mixed>> $extraLandmarks
     * @return array<int, array<string, mixed>>
     */
    private function mergeLandmarks(array $defaultLandmarks, array $extraLandmarks): array
    {
        $map = [];
        foreach (array_merge($defaultLandmarks, $extraLandmarks) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string)($item["name"] ?? "");
            if ($name === "") {
                continue;
            }
            $map[$name] = $item;
        }
        return array_values($map);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTownshipsByProvinceCode(string $provinceCode): array
    {
        if ($provinceCode === "") {
            return [];
        }
        if (isset($this->townshipChunkCache[$provinceCode])) {
            return $this->townshipChunkCache[$provinceCode];
        }
        $path = $this->regionDir . "/townships/" . $provinceCode . ".json";
        if (!is_file($path)) {
            $this->townshipChunkCache[$provinceCode] = [];
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            $this->townshipChunkCache[$provinceCode] = [];
            return [];
        }
        $decoded = json_decode($json, true);
        $data = is_array($decoded) ? $decoded : [];
        $this->townshipChunkCache[$provinceCode] = $data;
        return $data;
    }

    private function readJson(string $file): array
    {
        $path = $this->regionDir . "/" . $file;
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function endsWith(string $str, string $suffix): bool
    {
        if ($suffix === "") {
            return true;
        }
        $len = mb_strlen($suffix);
        if (mb_strlen($str) < $len) {
            return false;
        }
        return mb_substr($str, -$len) === $suffix;
    }
}

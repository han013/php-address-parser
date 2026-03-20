# hanhan/address-parser

中文地址解析 PHP 库，支持省/市/区/乡镇识别与口岸等标志地区识别。

核心能力包括：

- 省/市/区识别
- 口岸等标志地区优先识别
- 直辖市 `city` 回填（避免空值）
- 乡镇分片加载（对应 JS 的异步分片策略）
- 口岸歧义处理（`口岸` 不误匹配 `口岸街道`）

## 安装

```bash
composer require hanhan/address-parser
```

## 快速使用

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use hanhan\AddressParser\AddressParser;

$parser = new AddressParser(); // 默认读取包内 data/region 数据

$result = $parser->parseWithChunkTownships("北京市朝阳区建国路");
print_r($result);
```

## 外部扩展口岸

```php
$result = $parser->parseWithChunkTownships("某某新口岸", [
    "landmarks" => [
        [
            "name" => "某某新口岸",
            "aliases" => ["某某新"],
            "province" => "云南省",
            "city" => "临沧市",
            "district" => "某某县",
            "type" => "口岸",
        ],
    ],
]);
```

## API

- `parse(string $rawAddress, array $options = []): array`
  - 同步基础解析，不自动加载乡镇分片
- `parseWithChunkTownships(string $rawAddress, array $options = []): array`
  - 先基础解析，再按省份加载 `data/region/townships/*.json` 做二次解析

`$options` 支持：

- `divisionTree`：自定义省市区树
- `landmarks`：追加/覆盖标志地区（按 `name` 去重，后者覆盖前者）
- `townships`：额外乡镇数据

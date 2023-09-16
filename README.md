## why this project

在使用 hypef watcher 的时候发现自带的 ScanFileDriver 文件稍微多点会导致 CPU 负载太高, 于是自己写了一个 StatFileDriver 占用率降低了很多.

## 原理

使用stat函数获取文件的修改时间, 然后每次扫描的时候对比修改时间, 如果修改时间不一致则重新加载文件.

## hypef 版本
2.0 or 3.0
swow扩展跟swoole扩展都可以

## 兼容的平台

- Linux
- MacOS
- Windows [?] 没测试应该是可以的

## 安装
`composer require dmls/hypef-watch-stat`

## 使用
修改 .watcher 文件 把里面的driver换成 StatFileDriver::class
```php

return [
    'driver' => StatFileDriver::class,
    'bin' => 'php -d extension=swow',
    'watch' => [
        'dir' => ['./app','./config'],
        'file' => ['.env'],
        'scan_interval' => 2,
    ],
    'ext' => ['.env', '.php'],
];

```
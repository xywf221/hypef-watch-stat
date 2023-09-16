<?php

namespace Dmls\Watcher;

use ArrayObject;
use Hyperf\Engine\Channel;
use Hyperf\Watcher\Driver\DriverInterface;
use Hyperf\Watcher\Option;
use SplFileInfo;
use function file_exists;

/**
 * @description 通过 stat 函数实现的文件变更监听驱动 灵感来自于 libfswatch
 */
class StatFileDriver implements DriverInterface
{
    /**
     * @var bool allow symlinks
     */
    private bool $followSymlinks = false;

    /**
     * @var bool recursively
     */
    private bool $recursively = true;

    /**
     * @var string[]
     */
    private array $watchFilepaths;

    private ArrayObject $previousData;

    private ArrayObject $newData;

    private Channel $channel;

    public function __construct(private Option $option)
    {
        $this->previousData = new ArrayObject();
        $this->newData = new ArrayObject();
    }

    public function watch(Channel $channel): void
    {
        $this->channel = $channel;
        $this->watchFilepaths = array_map(
            fn(string $filepath) => BASE_PATH . '/' . $filepath,
            array_merge($this->option->getWatchFile(), $this->option->getWatchDir())
        );

        $this->initialData();

        while (true) {
            $this->collectData();
            sleep($this->option->getScanInterval());
        }
    }

    private function collectData(): void
    {
        $handler = [$this, 'intermediateScanHandler'];
        foreach ($this->watchFilepaths as $filepath) {
            $this->scan($filepath, $handler);
        }
        // has been deleted
        if ($this->previousData->count() > 0) {
            foreach ($this->previousData as $filepath) {
                $this->channel->push($filepath);
            }
        }
        //swap data
        $this->previousData->exchangeArray($this->newData);
        $this->newData = new ArrayObject();
    }

    private function intermediateScanHandler(string $filepath, SplFileInfo $fileInfo): bool
    {
        // file exists, skip
        if ($this->newData->offsetExists($filepath)) {
            return false;
        }
        $this->newData->offsetSet($filepath, [$fileInfo->getMTime(), $fileInfo->getCTime()]);

        // file has last scan
        if ($this->previousData->offsetExists($filepath)) {
            $wfi = $this->previousData->offsetGet($filepath);
            if ($fileInfo->getMTime() > $wfi[0] or $fileInfo->getCTime() > $wfi[1]) {
                // file has been updated
                $this->channel->push($filepath);
            }
            $this->previousData->offsetUnset($filepath);
        } else {
            // file is new
            $this->channel->push($filepath);
        }
        return true;
    }

    private function initialData(): void
    {
        $handler = [$this, 'initialHandler'];
        foreach ($this->watchFilepaths as $filepath) {
            $this->scan($filepath, $handler);
        }
    }

    private function initialHandler(string $filepath, SplFileInfo $fileInfo): bool
    {
        if ($this->previousData->offsetExists($filepath)) {
            return false;
        }
        $this->previousData->offsetSet($filepath, [$fileInfo->getMTime(), $fileInfo->getCTime()]);
        return true;
    }

    private function scan(string $filepath, callable $handler): void
    {
        if (!file_exists($filepath)) {
            return;
        }

        // clear cache
        clearstatcache(filename: $filepath);
        $fileInfo = new SplFileInfo($filepath);

        if ($this->followSymlinks && $fileInfo->isLink()) {
            if ($target = $fileInfo->getLinkTarget()) {
                $this->scan($target, $handler);
            }
        }

        if (!$this->acceptPath($fileInfo)) {
            return;
        }

        if (!call_user_func($handler, $filepath, $fileInfo)) {
            return;
        }

        // enable recursive
        if (!$this->recursively) {
            return;
        }

        // is dir
        if (!$fileInfo->isDir()) {
            return;
        }

        $this->walkDirectory($filepath, fn(string $filename) => $this->scan($filepath . '/' . $filename, $handler));
    }

    private function walkDirectory(string $filepath, callable $handler): void
    {
        if (!$handle = opendir($filepath)) {
            return;
        }

        while (false !== ($entry = readdir($handle))) {
            if ($entry === '.' or $entry === '..') {
                continue;
            }
            $handler($entry);
        }

        closedir($handle);
    }

    private function acceptPath(SplFileInfo $fileInfo): bool
    {
        // check file extension
        return in_array('.' . $fileInfo->getExtension(), $this->option->getExt());
    }
}

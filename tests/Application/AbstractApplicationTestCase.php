<?php
declare(strict_types=1);

namespace EonX\EasyHttpClient\Tests\Application;

use EonX\EasyHttpClient\Tests\Fixture\App\Kernel\ApplicationKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class AbstractApplicationTestCase extends KernelTestCase
{
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        $filesystem = new Filesystem();
        $var = __DIR__ . '/../Fixture/app/var';

        if ($filesystem->exists($var)) {
            $filesystem->remove($var);
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ApplicationKernel('test', false);
    }
}

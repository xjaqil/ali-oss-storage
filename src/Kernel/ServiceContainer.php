<?php


namespace Xjaqil\AliOSS\Kernel;


use Pimple\Container;
use Aqil\SiluAi\Kernel\Providers\ConfigServiceProvider;

/**
 *
 * @property Config $config
 */
class ServiceContainer extends Container
{


    /**
     * @var array
     */
    protected array $defaultConfig = [];


    /**
     * Constructor.
     *
     * @param array $config
     * @param array $prepends
     * @param string|null $id
     */
    public function __construct(array $config = [], array $prepends = [], string $id = null)
    {
        parent::__construct($prepends);
    }

    public function getProviders(): array
    {
        return [
            ConfigServiceProvider::class,
        ];
    }

    public function __get(string $id)
    {

        return $this->offsetGet($id);
    }

    public function __set($id, $value)
    {
        $this->offsetSet($id, $value);
    }

}

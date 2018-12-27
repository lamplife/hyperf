<?php

namespace Hyperf\Di;


use Hyperf\Di\Aop\Ast;
use Hyperf\Di\Definition\FactoryDefinition;
use Hyperf\Di\Definition\ObjectDefinition;
use Hyperf\Utils\CoroutineLocker;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Container\ContainerInterface;

class ProxyFactory
{

    private static $map = [];

    /**
     * @var Ast
     */
    private $ast;

    public function __construct(ContainerInterface $container)
    {
        $parserFactory = new ParserFactory();
        $astParser = $parserFactory->create(ParserFactory::ONLY_PHP7);
        $this->ast = new Ast($astParser, new Standard());
    }

    public function createProxyDefinition(ObjectDefinition $definition): ObjectDefinition
    {
        $identifier = $definition->getName();
        if (isset(static::$map[$identifier])) {
            return static::$map[$identifier];
        }
        $proxyIdentifier = null;
        if ($definition instanceof FactoryDefinition) {
            $proxyIdentifier = $definition->getFactory() . '_' . md5($definition->getFactory());
            $proxyIdentifier && $definition->setTarget($proxyIdentifier);
            $this->loadProxy($definition->getName(), $definition->getFactory());
        } elseif ($definition instanceof ObjectDefinition) {
            $proxyIdentifier = $definition->getClassName() . '_' . md5($definition->getClassName());
            $definition->setProxyClassName($proxyIdentifier);
            $this->loadProxy($definition->getClassName(), $definition->getProxyClassName());
        }
        static::$map[$identifier] = $definition;
        return static::$map[$identifier];
    }

    private function loadProxy(string $className, string $proxyClassName)
    {
        $dir = BASE_PATH . '/runtime/container/proxy/';
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $proxyFileName = str_replace('\\', '_', $proxyClassName);
        $path = $dir . $proxyFileName . '.proxy.php';

        $key = md5($path);
        if (CoroutineLocker::lock($key)) {
            // @TODO handle unlink mechanism.
            @unlink($path);
            if (! file_exists($path)) {
                $this->createProxyFile($path, $className, $proxyClassName);
            }
            include_once $path;
            CoroutineLocker::unlock($key);
        }
    }

    private function createProxyFile(string $path, string $className, string $proxyClassName)
    {
        $code = $this->ast->proxy($className, $proxyClassName);
        file_put_contents($path, $code);
    }
}
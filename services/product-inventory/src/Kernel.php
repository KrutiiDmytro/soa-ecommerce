<?php

namespace App;

use App\Domain\ProductRepository;
use App\Infrastructure\DoctrineProductRepository;
use App\Soap\SoapEndpointController;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new DoctrineMigrationsBundle(),
        ];
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => '%env(APP_SECRET)%',
            'http_method_override' => false,
            'cache' => [
                'app' => 'cache.adapter.redis',
                'default_redis_provider' => '%env(REDIS_DSN)%',
                'pools' => [
                    'catalog.cache' => ['adapter' => 'cache.app', 'tags' => true],
                ],
            ],
        ]);

        $c->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:PRODUCT_DATABASE_URL)%',
                'use_savepoints' => true,
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
                'enable_lazy_ghost_objects' => true,
                'report_fields_where_declared' => true,
                'validate_xml_mapping' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'auto_mapping' => false,
                'mappings' => [
                    'App' => [
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/src/Domain',
                        'prefix' => 'App\\Domain',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $c->extension('doctrine_migrations', [
            'migrations_paths' => [
                'App\\Migrations' => '%kernel.project_dir%/migrations',
            ],
        ]);

        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', __DIR__.'/')
            ->exclude([__DIR__.'/Kernel.php', __DIR__.'/Domain']);

        $services->alias(ProductRepository::class, DoctrineProductRepository::class);

        $services->set(HealthController::class)->public()->tag('controller.service_arguments');
        $services->set(SoapEndpointController::class)->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('health', '/health')->controller(HealthController::class);
        $routes->add('soap', '/soap')->controller(SoapEndpointController::class);
    }
}

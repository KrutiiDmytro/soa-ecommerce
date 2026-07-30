<?php

namespace App;

use App\Domain\EventPublisher;
use App\Domain\PaymentProvider;
use App\Domain\PaymentRepository;
use App\Domain\ShipmentRepository;
use App\Domain\ShippingProvider;
use App\Infrastructure\AmqpEventPublisher;
use App\Infrastructure\DoctrinePaymentRepository;
use App\Infrastructure\DoctrineShipmentRepository;
use App\Infrastructure\FakePaymentProvider;
use App\Infrastructure\FakeShippingProvider;
use App\Infrastructure\NovaPoshtaShippingProvider;
use App\Infrastructure\ProviderFactory;
use App\Infrastructure\StripePaymentProvider;
use App\Soap\SoapEndpointController;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
        ]);

        $c->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:FULFILLMENT_DATABASE_URL)%',
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

        $services->alias(PaymentRepository::class, DoctrinePaymentRepository::class);
        $services->alias(ShipmentRepository::class, DoctrineShipmentRepository::class);

        $services->set(StripePaymentProvider::class)->args(['%env(STRIPE_SECRET_KEY)%']);
        $services->set(NovaPoshtaShippingProvider::class)->args(['%env(NOVAPOSHTA_API_KEY)%']);
        $services->set(AmqpEventPublisher::class)->args(['%env(RABBITMQ_DSN)%']);
        $services->alias(EventPublisher::class, AmqpEventPublisher::class);

        // Провайдери обираються за env — домен бачить лише порт.
        $services->set(PaymentProvider::class)
            ->factory([ProviderFactory::class, 'payment'])
            ->args([service(FakePaymentProvider::class), service(StripePaymentProvider::class), '%env(PAYMENT_PROVIDER)%']);
        $services->set(ShippingProvider::class)
            ->factory([ProviderFactory::class, 'shipping'])
            ->args([service(FakeShippingProvider::class), service(NovaPoshtaShippingProvider::class), '%env(SHIPPING_PROVIDER)%']);

        $services->set(HealthController::class)->public()->tag('controller.service_arguments');
        $services->set(SoapEndpointController::class)->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('health', '/health')->controller(HealthController::class);
        $routes->add('soap', '/soap')->controller(SoapEndpointController::class);
    }
}

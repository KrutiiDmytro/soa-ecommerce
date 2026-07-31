<?php

namespace App;

use App\Domain\MailGateway;
use App\Infrastructure\FakeMailGateway;
use App\Infrastructure\MailGatewayFactory;
use App\Infrastructure\SesMailGateway;
use App\Soap\SoapEndpointController;
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
        // Сервіс stateless — Doctrine і власної БД у нього немає (за дизайном Task-27).
        return [new FrameworkBundle()];
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
                    // Дедуплікація оброблених подій: кеш із TTL, а не сховище стану сервісу.
                    'notification.cache' => ['adapter' => 'cache.app'],
                ],
            ],
        ]);

        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', __DIR__.'/')
            ->exclude([__DIR__.'/Kernel.php', __DIR__.'/Domain/Message.php']);

        $services->set(FakeMailGateway::class)->args(['%kernel.project_dir%/var/mail/sent.log']);
        $services->set(SesMailGateway::class)->args(['%env(AWS_ACCESS_KEY_ID)%', '%env(AWS_DEFAULT_REGION)%']);

        // Шлюз обирається за env — домен бачить лише порт.
        $services->set(MailGateway::class)
            ->factory([MailGatewayFactory::class, 'create'])
            ->args([service(FakeMailGateway::class), service(SesMailGateway::class), '%env(NOTIFICATION_GATEWAY)%']);

        $services->set(HealthController::class)->public()->tag('controller.service_arguments');
        $services->set(SoapEndpointController::class)->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('health', '/health')->controller(HealthController::class);
        $routes->add('soap', '/soap')->controller(SoapEndpointController::class);
    }
}

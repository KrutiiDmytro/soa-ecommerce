<?php

namespace App;

use App\Gateway\EsbGatewayController;
use App\Registry\RegistryController;
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
        // ESB не має власних даних: ані Doctrine, ані БД — лише маршрутизація,
        // трансформація, оркестрація та governance-каталог.
        return [new FrameworkBundle()];
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => '%env(APP_SECRET)%',
            'http_method_override' => false,
        ]);

        $services = $c->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', __DIR__.'/')
            ->exclude([__DIR__.'/Kernel.php', __DIR__.'/Gateway/InspectedMessage.php']);

        $services->set(HealthController::class)->public()->tag('controller.service_arguments');
        $services->set(EsbGatewayController::class)->public()->tag('controller.service_arguments');
        $services->set(RegistryController::class)->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('health', '/health')->controller(HealthController::class);
        $routes->add('soap', '/soap')->controller(EsbGatewayController::class);
        $routes->add('registry', '/registry')->controller(RegistryController::class);
    }
}

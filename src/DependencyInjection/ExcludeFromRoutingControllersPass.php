<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\CronMonitor\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tira os controllers do bundle da descoberta automática de rotas.
 *
 * No Symfony 7.4 o autoconfigure marca toda classe com #[Route] como "routing.controller", e o
 * config/routes.yaml padrão (resource: routing.controllers) importa todas elas sem prefixo,
 * sobrescrevendo o import do projeto. As rotas do bundle só devem existir pelo import explícito de
 * config/routes.php (mesmo problema e mesma solução do champs-onboarding).
 */
final class ExcludeFromRoutingControllersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('routing.controller') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass() ?? $id;

            if (str_starts_with($class, 'BetoCampoy\\Champs\\CronMonitor\\')) {
                $container->getDefinition($id)->clearTag('routing.controller');
            }
        }
    }
}

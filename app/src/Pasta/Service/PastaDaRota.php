<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\DTO\PastaDaRequisicao;
use App\Pasta\Entity\Pasta;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;

/**
 * De onde sai a pasta de uma requisição, num ponto só (spec pasta-cadeado §3). Usado pelas barreiras de
 * `kernel.controller_arguments`: a lápide (`PastaSomenteLeituraListener`), o cadeado
 * (`BarreiraDoCadeadoListener`) e a aba oculta.
 *
 * A pasta sai, nesta ordem:
 *  1. dos argumentos já resolvidos da action: uma `Pasta`; uma filha com `getPasta()` (documento,
 *     seção, mensagem, checklist, observação, pagamento…); ou uma neta pela meta (`TarefaMensagem`:
 *     `getTarefa()->getPasta()`);
 *  2. de `#[PastaPelaFilha(entidade, argumento)]`: a filha carregada pelo id da rota;
 *  3. de `#[PastaPorId(argumento)]`: a pasta carregada pelo id da rota.
 *
 * A filha também é entregue: a primeira filha com `getPasta()` dos argumentos ou, sem ela, a carregada
 * pelo `#[PastaPelaFilha]`. Por isso a rota que recebe `Pasta $pasta` e o id cru de um documento ainda
 * entrega o documento, se declarar o atributo.
 *
 * Nos passos 2 e 3 a busca é por id E escritório da sessão (guarda IDOR): id de outro escritório não acha
 * nada, e a action responde o próprio 404. Sem escritório na sessão, esses passos devolvem nada. Os
 * argumentos do passo 1 chegam já filtrados pelo `TenantFilter`; quem precisa de "só do escritório da
 * sessão" confere `ehDoEscritorioDaSessao()`. Pasta de outro escritório passa reto pelas barreiras.
 */
final class PastaDaRota
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function resolver(ControllerArgumentsEvent $event): ?PastaDaRequisicao
    {
        [$pasta, $filha] = $this->dosArgumentos($event->getArguments());

        if ($filha === null) {
            $declarada = $this->filhaDeclarada($event);
            $filha     = $declarada;
            $pasta   ??= $declarada !== null ? self::pastaDe($declarada) : null;
        }

        $pasta ??= $this->pastaPorIdDeclarado($event);

        return $pasta !== null ? new PastaDaRequisicao($pasta, $filha) : null;
    }

    public function ehDoEscritorioDaSessao(Pasta $pasta): bool
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        return $tenant !== null && $pasta->getTenant()?->getId() === $tenant->getId();
    }

    /**
     * @param array<int, mixed> $argumentos
     *
     * @return array{0: ?Pasta, 1: ?object}
     */
    private function dosArgumentos(array $argumentos): array
    {
        $pasta = null;
        $filha = null;
        foreach ($argumentos as $argumento) {
            if ($argumento instanceof Pasta) {
                $pasta ??= $argumento;
                continue;
            }

            $daFilha = self::pastaDe($argumento);
            if ($daFilha !== null) {
                $filha ??= $argumento;
                $pasta ??= $daFilha;
                continue;
            }

            // Neta pela meta: `TarefaMensagem` não tem `getPasta()`, chega a ela por `getTarefa()`
            // (achado P14b). Meta sem pasta (ou objeto sem meta) segue reto.
            $pasta ??= self::pastaPelaMeta($argumento);
        }

        return [$pasta, $filha];
    }

    private function filhaDeclarada(ControllerArgumentsEvent $event): ?object
    {
        $declaracoes = $event->getAttributes(PastaPelaFilha::class);
        $tenant      = $this->tenantContext->getCurrentTenant();
        if ($declaracoes === [] || $tenant === null) {
            return null;
        }

        foreach ($declaracoes as $declaracao) {
            $id = $event->getRequest()->attributes->get($declaracao->argumento);
            if (!is_numeric($id)) {
                continue;
            }

            $filha = $this->em->getRepository($declaracao->entidade)->findOneBy(['id' => (int) $id, 'tenant' => $tenant]);
            if (is_object($filha) && self::pastaDe($filha) !== null) {
                return $filha;
            }
        }

        return null;
    }

    private function pastaPorIdDeclarado(ControllerArgumentsEvent $event): ?Pasta
    {
        $declaracoes = $event->getAttributes(PastaPorId::class);
        $tenant      = $this->tenantContext->getCurrentTenant();
        if ($declaracoes === [] || $tenant === null) {
            return null;
        }

        foreach ($declaracoes as $declaracao) {
            $id = $event->getRequest()->attributes->get($declaracao->argumento);
            if (!is_numeric($id)) {
                continue;
            }

            $pasta = $this->em->getRepository(Pasta::class)->findOneBy(['id' => (int) $id, 'tenant' => $tenant]);
            if ($pasta instanceof Pasta) {
                return $pasta;
            }
        }

        return null;
    }

    private static function pastaDe(mixed $objeto): ?Pasta
    {
        if (!is_object($objeto) || !method_exists($objeto, 'getPasta')) {
            return null;
        }
        $pasta = $objeto->getPasta();

        return $pasta instanceof Pasta ? $pasta : null;
    }

    private static function pastaPelaMeta(mixed $objeto): ?Pasta
    {
        if (!is_object($objeto) || !method_exists($objeto, 'getTarefa')) {
            return null;
        }

        return self::pastaDe($objeto->getTarefa());
    }
}

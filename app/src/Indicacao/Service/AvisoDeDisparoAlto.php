<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avisa os Masters no sino: "Disparo alto de convites" (spec `indicar-bluejus.md`, comportamento 10; texto do
 * desenho bj-contas.js L126).
 *
 * O sino é do escritório (Notificacao é TenantAware e auditada), então o aviso cai no vínculo ativo mais antigo do
 * Master numa CONTA DO MASTER, nunca no escritório de um cliente (o texto iria parar no audit_log que o cliente lê).
 * Conta do Master: o criador (`Tenant.criadoPor`) é Master; sem criador registrado, vale porque o próprio Master tem
 * vínculo ativo com ela. É a regra do `RegraDosMasters::ehContaDoMaster` (perfis-1.2-contas C5), que ainda não está
 * nesta base: replicada só no necessário. Master sem conta dele fica sem o aviso no sino (o alerta continua no card).
 */
final class AvisoDeDisparoAlto
{
    public const TIPO_NOTIFICACAO = 'indicacao_disparo_alto';
    public const TITULO = 'Disparo alto de convites';

    private const PAPEL_MASTER = 'ROLE_SUPER_ADMIN';

    public function __construct(
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Grava (sem flush: entra na transação de quem chama) uma notificação para cada Master ativo. */
    public function avisar(string $texto): void
    {
        $url = $this->urls->generate('master_painel') . '#aba=painel&sec=ind';
        foreach ($this->mastersAtivos() as $master) {
            $escritorio = $this->contaDoMaster($master);
            if ($escritorio === null) {
                continue;
            }
            $this->notificacoes->criar($master, $escritorio, self::TIPO_NOTIFICACAO, self::TITULO, $texto)->setUrl($url);
        }
    }

    /** @return list<User> */
    private function mastersAtivos(): array
    {
        return array_values(array_filter(
            $this->usuarios->findComPapelGlobal(self::PAPEL_MASTER),
            static fn (User $u): bool => $u->isActive() && in_array(self::PAPEL_MASTER, $u->getRoles(), true),
        ));
    }

    private function contaDoMaster(User $master): ?Tenant
    {
        $vinculos = array_values(array_filter(
            $this->vinculos->findActiveByUser($master),
            static fn (UserTenant $v): bool => self::ehContaDoMaster($v->getTenant()),
        ));
        usort($vinculos, static fn (UserTenant $a, UserTenant $b): int => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

        return ($vinculos[0] ?? null)?->getTenant();
    }

    /** Só chamado com vínculo ativo de um Master: sem criador registrado, esse vínculo já basta. */
    private static function ehContaDoMaster(Tenant $conta): bool
    {
        $criador = $conta->getCriadoPor();

        return $criador === null || in_array(self::PAPEL_MASTER, $criador->getRoles(), true);
    }
}

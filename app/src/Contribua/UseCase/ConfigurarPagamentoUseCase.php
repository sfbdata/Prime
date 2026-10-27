<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * "Salvar" do modal "Pagamento por cartão · só Master" (desenho 08). O controller exige
 * `ROLE_SUPER_ADMIN`; a configuração é auditada (`Auditavel`).
 *
 * Só aceita gateway com adaptador instalado (escolher um que não existe deixaria o checkout quebrado). Os
 * links dos pacotes são opcionais por plano pago: link vazio sai do mapa.
 */
final class ConfigurarPagamentoUseCase
{
    public function __construct(
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
        private readonly GatewaysDeAssinatura $gateways,
        private readonly PlanoRepository $planos,
        private readonly EscopoDeAuditoriaDePlataforma $auditoria,
    ) {
    }

    /** @param array<string, string> $links código do plano → link */
    public function executar(?string $gateway, ?string $chavePublica, array $links, int $diasDeCarencia): void
    {
        $gateway = ($gateway === null || trim($gateway) === '') ? null : trim($gateway);
        if ($gateway !== null && $this->gateways->porCodigo($gateway) === null) {
            throw new \DomainException('Este gateway ainda não está instalado no BlueJus.');
        }

        $configuracao = $this->configuracao->atual();
        $configuracao->configurar($gateway, self::vazioViraNulo($chavePublica), $this->linksDosPlanosPagos($links), $diasDeCarencia);
        // Configuração global: a auditoria é da plataforma, não do escritório em que o Master está.
        $this->auditoria->executar(fn () => $this->configuracao->salvar($configuracao, flush: true));
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, string>
     */
    private function linksDosPlanosPagos(array $links): array
    {
        $validos = [];
        foreach ($this->planos->ativosEmOrdem() as $plano) {
            $link = trim($links[$plano->getCodigo()] ?? '');
            if (!$plano->ehGratuito() && $link !== '') {
                $validos[$plano->getCodigo()] = $link;
            }
        }

        return $validos;
    }

    private static function vazioViraNulo(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_substr($texto, 0, 255);
    }
}

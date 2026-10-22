<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Armazenamento\ChavesDeComercial;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\ImagemDaCampanhaRepository;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O passo do Comercial no `app:purgar-dados-expirados` (spec comercial-e-emails.md §3.3 e §3.5): as confirmações de
 * endereço nunca confirmadas e vencidas há mais de 30 dias, e os disparos simulados ("Simular novo cadastro") com
 * mais de 7 dias. Fatia 2b (§8.5, R9): os destinatários de campanha com mais de 24 meses ficam com o e-mail em hash
 * SHA-256 (sal da aplicação) e sem o nome. A retenção de 24 meses de `comercial_envio` (P4) espera a decisão do dono.
 * F3i (§12): as imagens das campanhas vencidas (`expira_em < agora`) saem do banco num DELETE que se confirma sozinho,
 * e só depois os arquivos, pela {@see RemocaoAposTransacao} (falha do storage vira log e órfão recuperável, INV-6). No
 * dry-run, só a contagem.
 */
final class PurgarDadosDoComercialUseCase
{
    public const CARENCIA_DA_SIMULACAO = '-7 days';
    public const RETENCAO_DOS_DESTINATARIOS = '-24 months';

    public function __construct(
        private readonly PurgarConfirmacoesVencidasUseCase $confirmacoes,
        private readonly Connection $conexao,
        private readonly DestinoDaCampanhaRepository $destinos,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $salDaAplicacao,
        private readonly ImagemDaCampanhaRepository $imagens,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    /** Nunca lança: uma falha aqui não pode impedir os passos seguintes do comando (a purga dos escritórios). */
    public function executar(\DateTimeImmutable $agora, bool $dryRun): int
    {
        try {
            return $this->confirmacoes->executar($agora, $dryRun) + $this->simulacoes($agora, $dryRun) + $this->destinatarios($agora, $dryRun) + $this->imagensVencidas($agora, $dryRun);
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: purga dos dados expirados falhou.', ['erro' => $erro::class]);

            return 0;
        }
    }

    private function imagensVencidas(\DateTimeImmutable $agora, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->imagens->contarVencidas($agora);
        }
        $nomes = $this->imagens->apagarVencidas($agora);
        if ($nomes !== []) {
            $this->remocao->remover(array_map(ChavesDeComercial::imagemPorNome(...), $nomes), 'comercial_imagem_vencida');
        }

        return \count($nomes);
    }

    private function destinatarios(\DateTimeImmutable $agora, bool $dryRun): int
    {
        $limite = $agora->modify(self::RETENCAO_DOS_DESTINATARIOS);

        return $dryRun ? $this->destinos->contarParaAnonimizar($limite) : $this->destinos->anonimizarAntesDe($limite, $this->salDaAplicacao);
    }

    private function simulacoes(\DateTimeImmutable $agora, bool $dryRun): int
    {
        $onde = "situacao = 'simulado' AND criado_em < :limite";
        $limite = ['limite' => $agora->modify(self::CARENCIA_DA_SIMULACAO)->format('Y-m-d H:i:s')];

        return $dryRun
            ? (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE ' . $onde, $limite)
            : (int) $this->conexao->executeStatement('DELETE FROM comercial_envio WHERE ' . $onde, $limite);
    }
}

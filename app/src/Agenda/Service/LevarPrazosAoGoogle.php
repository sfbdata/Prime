<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\PrazoNoGoogle;
use App\Agenda\Exception\EventoRecusadoPeloGoogleException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\PrazoNoGoogleRepository;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\AcessoAPasta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Os prazos e audiências das pastas na agenda do escritório no Google de quem tem "Ver prazos na agenda"
 * (decisão M7 do dono, spec agenda-google §7). A cada sincronia: o que deixou de valer para a pessoa sai de lá
 * (cumprido, excluído, pasta na lixeira ou oculta para ela, fora da janela, sem a permissão) e o que mudou vai.
 * Só leitura: o que mexerem lá volta como aqui; apagado lá fica apagado até o prazo mudar aqui.
 */
final class LevarPrazosAoGoogle
{
    private const DESDE = '-30 days';
    private const ATE = '+365 days';

    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly PrazoParaOGoogle $traducao,
        private readonly PastaPrazoRepository $prazos,
        private readonly PrazoNoGoogleRepository $linhas,
        private readonly PermissoesDaAgenda $daAgenda,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        // Cadeado (spec pasta-cadeado D3): prazo de pasta oculta para a pessoa não vai à agenda dela.
        private readonly ?AcessoAPasta $acessoAPasta = null,
    ) {
    }

    /** @return array{enviados: int, apagados: int} */
    public function levar(ConexaoGoogleAgenda $conexao, string $acesso, \DateTimeImmutable $agora): array
    {
        $valem = $this->queValem($conexao, $agora);
        $apagados = 0;
        $guardadas = [];
        foreach ($this->linhas->daConexao($conexao) as $linha) {
            $prazoId = $linha->getPrazo()?->getId();
            if (null === $prazoId || !isset($valem[$prazoId])) {
                $this->tirar($conexao, $acesso, $linha);
                ++$apagados;
                continue;
            }
            $guardadas[$prazoId] = $linha;
        }
        $enviados = 0;
        foreach ($valem as $prazoId => $prazo) {
            $enviados += $this->enviarUm($conexao, $acesso, $prazo, $guardadas[$prazoId] ?? null) ? 1 : 0;
        }

        return ['enviados' => $enviados, 'apagados' => $apagados];
    }

    /**
     * Veio do Google um evento com id de prazo: nunca vira compromisso. Igual ao último envio é eco; apagado lá
     * fica apagado até mudar aqui; mexido lá vai de novo como aqui.
     *
     * @param array<string, mixed> $item
     */
    public function mexeramLa(ConexaoGoogleAgenda $conexao, array $item, string $googleId): void
    {
        $linha = $this->linhas->daConexaoPeloGoogle($conexao, $googleId);
        $prazo = $linha?->getPrazo();
        if (null === $linha || null === $prazo || $this->traducao->resumoDoItem($item) === $linha->getHash()) {
            return;
        }
        if ('cancelled' !== ($item['status'] ?? null)) {
            $linha->mexidoLa();

            return;
        }
        // Marca com o último envio (não com o prazo de agora): mudança feita aqui antes desta volta ainda vai.
        $guardado = $linha->getHash();
        if (null === $guardado || !str_starts_with($guardado, PrazoNoGoogle::APAGADO)) {
            $linha->apagadoLa($guardado ?? $this->traducao->resumo($prazo));
        }
    }

    public function ehIdDePrazo(string $googleId): bool
    {
        return $this->traducao->ehIdDePrazo($googleId);
    }

    /** @return array<int, PastaPrazo> os prazos que a pessoa deve ter na agenda dela, pelo id */
    private function queValem(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $agora): array
    {
        $pessoa = $conexao->getUsuario();
        $tenant = $conexao->getTenant();
        if (!$this->daAgenda->verPrazos($pessoa, $tenant)) {
            return [];
        }
        $ocultas = $this->acessoAPasta?->idsOcultosPara($pessoa, (int) $tenant->getId()) ?? [];
        $valem = [];
        foreach ($this->prazos->abertosDoEscritorioNoPeriodo($tenant, $agora->modify(self::DESDE), $agora->modify(self::ATE), $ocultas) as $prazo) {
            $valem[(int) $prazo->getId()] = $prazo;
        }

        return $valem;
    }

    private function tirar(ConexaoGoogleAgenda $conexao, string $acesso, PrazoNoGoogle $linha): void
    {
        try {
            $this->google->apagar($acesso, (string) $conexao->getCalendarioId(), $linha->getGoogleId());
        } catch (EventoRecusadoPeloGoogleException $e) {
            $this->logger->info('Google Agenda: prazo não apagado, deixado de lado', ['motivo' => $e->getMessage()]);
        }
        $this->linhas->remover($linha);
        $this->em->flush();
    }

    private function enviarUm(ConexaoGoogleAgenda $conexao, string $acesso, PastaPrazo $prazo, ?PrazoNoGoogle $linha): bool
    {
        $resumo = $this->traducao->resumo($prazo);
        if (null !== $linha && in_array($linha->getHash(), [$resumo, PrazoNoGoogle::APAGADO . $resumo], true)) {
            return false;
        }
        $linha ??= new PrazoNoGoogle($conexao->getTenant(), $conexao, $prazo, $this->traducao->idPara($prazo));
        try {
            $this->google->gravar($acesso, (string) $conexao->getCalendarioId(), $linha->getGoogleId(), $this->traducao->corpo($prazo), null !== $linha->getId());
            $linha->enviado($resumo);
        } catch (EventoRecusadoPeloGoogleException $e) {
            $this->logger->info('Google Agenda: prazo recusado, fica de fora até mudar', ['prazo' => $prazo->getId(), 'motivo' => $e->getMessage()]);
            $linha->apagadoLa($resumo);
        }
        $this->linhas->salvar($linha);
        $this->em->flush();

        return $resumo === $linha->getHash();
    }
}

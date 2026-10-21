<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Contract\DatasParaOGoogle;
use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\DataNoGoogle;
use App\Agenda\Exception\EventoRecusadoPeloGoogleException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\DataNoGoogleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * As datas do sistema na agenda do escritório no Google de cada pessoa conectada (decisão do dono, spec agenda-google
 * §9): TUDO o que a Agenda mostra sem ninguém cadastrar (feriados, recesso, tribunais e eleições, comemorativas, datas
 * do escritório e aniversários da equipe), com os ajustes do escritório, na UF da lotação da pessoa, como na grade
 * dela. A cada sincronia, o que deixou de valer sai de lá e o que mudou vai. Só leitura, como os prazos.
 */
final class LevarDatasAoGoogle
{
    private const DESDE = '-30 days';
    private const ATE = '+365 days';

    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly DataParaOGoogle $traducao,
        private readonly DatasParaOGoogle $datas,
        private readonly DataNoGoogleRepository $linhas,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{enviados: int, apagados: int} */
    public function levar(ConexaoGoogleAgenda $conexao, string $acesso, \DateTimeImmutable $agora): array
    {
        $valem = $this->queValem($conexao, $agora);
        $apagados = 0;
        $guardadas = [];
        foreach ($this->linhas->daConexao($conexao) as $linha) {
            if (!isset($valem[$linha->getChave()])) {
                $this->tirar($conexao, $acesso, $linha);
                ++$apagados;
                continue;
            }
            $guardadas[$linha->getChave()] = $linha;
        }
        $enviados = 0;
        foreach ($valem as $chave => $corpo) {
            $enviados += $this->enviarUm($conexao, $acesso, $chave, $corpo, $guardadas[$chave] ?? null) ? 1 : 0;
        }

        return ['enviados' => $enviados, 'apagados' => $apagados];
    }

    /**
     * Veio do Google um evento com id de data do sistema: nunca vira compromisso. Igual ao último envio é eco; apagado
     * lá fica apagado até a data mudar aqui (marcado com o último envio); mexido lá vai de novo como aqui.
     *
     * @param array<string, mixed> $item
     */
    public function mexeramLa(ConexaoGoogleAgenda $conexao, array $item, string $googleId): void
    {
        $linha = $this->linhas->daConexaoPeloGoogle($conexao, $googleId);
        if (null === $linha || $this->traducao->resumoDoItem($item) === $linha->getHash()) {
            return;
        }
        if ('cancelled' !== ($item['status'] ?? null)) {
            $linha->mexidoLa();

            return;
        }
        $guardado = $linha->getHash();
        if (null !== $guardado && !str_starts_with($guardado, DataNoGoogle::APAGADO)) {
            $linha->apagadoLa($guardado);
        }
    }

    public function ehIdDeData(string $googleId): bool
    {
        return $this->traducao->ehIdDeData($googleId);
    }

    /** @return array<string, array<string, mixed>> chave da data => corpo no Google */
    private function queValem(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $agora): array
    {
        $valem = [];
        foreach ($this->datas->daConexao($conexao, $agora->modify(self::DESDE)->setTime(0, 0), $agora->modify(self::ATE)->setTime(0, 0)) as $chave => $d) {
            $valem[$chave] = $this->traducao->corpo($d['data'], $d['titulo'], $d['observacao']);
        }

        return $valem;
    }

    private function tirar(ConexaoGoogleAgenda $conexao, string $acesso, DataNoGoogle $linha): void
    {
        try {
            $this->google->apagar($acesso, (string) $conexao->getCalendarioId(), $linha->getGoogleId());
        } catch (EventoRecusadoPeloGoogleException $e) {
            $this->logger->info('Google Agenda: data do sistema não apagada, deixada de lado', ['motivo' => $e->getMessage()]);
        }
        $this->linhas->remover($linha);
        $this->em->flush();
    }

    /** @param array<string, mixed> $corpo */
    private function enviarUm(ConexaoGoogleAgenda $conexao, string $acesso, string $chave, array $corpo, ?DataNoGoogle $linha): bool
    {
        $resumo = $this->traducao->resumoDoItem($corpo);
        if (null !== $linha && in_array($linha->getHash(), [$resumo, DataNoGoogle::APAGADO . $resumo], true)) {
            return false;
        }
        $linha ??= new DataNoGoogle($conexao->getTenant(), $conexao, $chave, $this->traducao->idPara((int) $conexao->getTenant()->getId(), $chave));
        try {
            $this->google->gravar($acesso, (string) $conexao->getCalendarioId(), $linha->getGoogleId(), $corpo, null !== $linha->getId());
            $linha->enviado($resumo);
        } catch (EventoRecusadoPeloGoogleException $e) {
            $this->logger->info('Google Agenda: data do sistema recusada, fica de fora até mudar', ['motivo' => $e->getMessage()]);
            $linha->apagadoLa($resumo);
        }
        $this->linhas->salvar($linha);
        $this->em->flush();

        return $resumo === $linha->getHash();
    }
}

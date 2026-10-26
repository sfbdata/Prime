<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlterarConvenioDaUfInput;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Exception\AvisoDeConvenioNaoEnfileirado;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Service\AvisoDeConvenioDaUf;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Muda o convênio OAB de um estado (desenho 05 Perfis 1.2 VS 25.10.26): grátis, valor diferenciado ou sem convênio.
 *
 * Quem dispara: o Master, no painel "Convênio OAB por estado" da seção Contas (ROLE_SUPER_ADMIN no controller).
 * Pré-condições: UF conhecida; em `dif` e `sem`, valor de 0 a 999.999,99 e data AAAA-MM-DD.
 * Fluxo principal: `livre` apaga a linha do estado; `dif` e `sem` criam ou trocam a linha. Grava no escopo de
 * plataforma. Auditoria: ConvenioDaUf é Auditavel, cada criação, troca e remoção entra na trilha do Master.
 * Depois de gravar, enfileira um e-mail a cada primário ativo dos escritórios do estado ({@see AvisoDeConvenioDaUf}, P4c),
 * só se o estado ou o valor mudou (motivo e data sozinhos gravam sem avisar); não muda cobrança (frente própria).
 * Fluxos alternativos: dado fora da regra recusa (422), sem gravar.
 */
final class AlterarConvenioDaUfUseCase
{
    public function __construct(
        private readonly ConvenioDaUfRepository $convenios,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly AvisoDeConvenioDaUf $aviso,
        private readonly Connection $conexao,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Tudo numa transação da conexão, com trava por UF: ler, comparar, gravar e enfileirar os avisos. Falha ao enfileirar
     * desfaz a gravação; o segundo POST simultâneo espera a trava, vê o estado igual e devolve 0. O Carteiro usa a mesma
     * conexão (aninha na transação, sem commit próprio).
     *
     * @return int quantos avisos por e-mail entraram na fila (0 quando nada mudou)
     */
    public function executar(AlterarConvenioDaUfInput $input): int
    {
        $uf = ConvenioDaUf::exigirUf($input->uf);
        $novo = $input->modo === 'livre' ? null : $this->convenioPedido($uf, $input);

        try {
            return $this->conexao->transactional(function () use ($uf, $novo, $input): int {
                $this->conexao->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('convenio_oab:' || ?, 0))", [$uf]);

                return $this->aplicar($uf, $this->convenios->buscar($uf), $novo, $input);
            });
        } catch (ConvenioRecusado $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Convênio OAB: a mudança foi desfeita porque os avisos não entraram na fila.', ['uf' => $uf, 'erro' => $e::class]);

            throw new AvisoDeConvenioNaoEnfileirado('Avisos não enfileirados.', 0, $e);
        }
    }

    private function aplicar(string $uf, ?ConvenioDaUf $atual, ?ConvenioDaUf $novo, AlterarConvenioDaUfInput $input): int
    {
        if ($novo === null) {
            return $atual === null ? 0 : $this->tornarGratuito($uf, $atual);
        }
        if ($atual?->igualA($novo)) {
            return 0;
        }

        $avisar = $atual === null || !$atual->mesmoAvisoQue($novo);
        $destinatarios = $avisar ? $this->aviso->destinatarios($uf) : [];
        if ($atual === null) {
            $atual = $novo;
        } else {
            $atual->definir($novo->getModo(), $novo->getValor(), $novo->getDesde(), $novo->getMotivo(), $input->ator, $this->relogio->now());
        }
        $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($atual, flush: true));

        return $avisar ? $this->aviso->avisar($uf, $atual, $destinatarios) : 0;
    }

    private function tornarGratuito(string $uf, ConvenioDaUf $atual): int
    {
        $destinatarios = $this->aviso->destinatarios($uf);
        $this->escopoDePlataforma->executar(fn () => $this->convenios->remover($atual, flush: true));

        return $this->aviso->avisar($uf, null, $destinatarios);
    }

    private function convenioPedido(string $uf, AlterarConvenioDaUfInput $input): ConvenioDaUf
    {
        $valor = $input->valor ?? throw new ConvenioRecusado('Valor inválido.');

        return new ConvenioDaUf($uf, $input->modo, $valor, $this->data($input->desde), $input->motivo, $input->ator, $this->relogio->now());
    }

    private function data(string $texto): \DateTimeImmutable
    {
        $desde = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if ($desde === false || $desde->format('Y-m-d') !== $texto) {
            throw new ConvenioRecusado('Data inválida. Use AAAA-MM-DD.');
        }

        return $desde;
    }
}

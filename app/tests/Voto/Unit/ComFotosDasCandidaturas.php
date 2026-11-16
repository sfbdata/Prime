<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use App\Voto\Service\FotosDasCandidaturas;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monta o `FotosDasCandidaturas` dos testes de UseCase do Voto: armazenamento em memória (a chave inclui o escopo),
 * `TransacaoComArquivoNovo` REAL sobre um EntityManager e uma conexão que se comportam como a transação (o COMMIT fica
 * registrado em `$ordem`, junto com as exclusões, para provar que o arquivo antigo só sai depois dele).
 */
trait ComFotosDasCandidaturas
{
    protected ArmazenamentoEmMemoria $armazenamento;
    protected LoggerEmMemoria $logger;

    /** @var list<string> */
    protected array $ordem = [];

    protected function fotosDasCandidaturas(?\Throwable $noFlush = null): FotosDasCandidaturas
    {
        $this->armazenamento ??= new ArmazenamentoEmMemoria();
        $this->logger ??= new LoggerEmMemoria();
        $this->armazenamento->aoExcluir = function (): void {
            $this->ordem[] = 'excluir';
        };
        $nivel = 0;
        $conexao = $this->createStub(Connection::class);
        $conexao->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conexao->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conexao->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $conexao->method('commit')->willReturnCallback(function () use (&$nivel): void {
            --$nivel;
            $this->ordem[] = 'commit';
        });
        $conexao->method('fetchOne')->willReturn('11117396');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexao);
        if (null !== $noFlush) {
            $em->method('flush')->willThrowException($noFlush);
        }
        $remocao = new RemocaoAposTransacao($this->armazenamento, $this->logger);

        return new FotosDasCandidaturas(
            $this->armazenamento,
            new TransacaoComArquivoNovo($em, new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), $remocao, $this->logger),
            $remocao,
        );
    }
}

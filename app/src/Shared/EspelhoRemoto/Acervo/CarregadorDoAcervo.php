<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Acervo;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\MedidaDeConteudo;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;

/**
 * A carga do acervo existente (backfill, P2.6, spec P2 §3.5): para cada arquivo que o banco referencia
 * numa categoria, registra um envio na outbox, a menos que a última linha da mesma chave remota já seja
 * envio verificado, pendente ou em processamento. Idempotente: rodar de novo não duplica a fila.
 *
 * Lê só do disco local (a mesma função de chave do decorador), não fala com o R2 e não envia nada: quem
 * envia é o worker, no ritmo dele (50 por minuto no agendador), o que limita a banda.
 */
final class CarregadorDoAcervo
{
    /** Últimos estados que dispensam um envio novo. */
    private const JA_RESOLVIDOS = [
        OutboxDeReplicacao::ESTADO_VERIFICADO,
        OutboxDeReplicacao::ESTADO_PENDENTE,
        OutboxDeReplicacao::ESTADO_PROCESSANDO,
    ];

    public function __construct(
        private readonly FonteDeReferencias $fontes,
        private readonly ArmazenamentoDeArquivos $local,
        private readonly OutboxDeReplicacao $outbox,
    ) {
    }

    public function carregar(CategoriaDeArquivo $categoria, ?int $limite = null, bool $simular = false): ResumoDaCarga
    {
        $resumo = new ResumoDaCarga();

        foreach ($this->fontes->referencias() as $referencia) {
            if ($referencia->categoria() !== $categoria) {
                continue;
            }
            if ($limite !== null && $resumo->enfileirados >= $limite) {
                break;
            }
            ++$resumo->vistos;

            $chave = $referencia->chave;
            if ($chave === null) {
                ++$resumo->chavesInvalidas;

                continue;
            }

            $this->carregarUma($chave, $simular, $resumo);
        }

        return $resumo;
    }

    private function carregarUma(ChaveDeArquivo $chave, bool $simular, ResumoDaCarga $resumo): void
    {
        if (\in_array($this->outbox->ultimoEstadoDe(LayoutDeChaveRemota::chaveDe($chave)), self::JA_RESOLVIDOS, true)) {
            ++$resumo->jaNaFilaOuVerificados;

            return;
        }

        try {
            $metadados = $this->local->metadados($chave);
            if ($metadados === null) {
                ++$resumo->locaisAusentes;

                return;
            }
            if ($simular) {
                ++$resumo->enfileirados;

                return;
            }

            $medida = MedidaDeConteudo::de($this->local, $chave);
            $this->outbox->registrarEnvio($chave, $medida->sha256, $medida->tamanhoBytes, $metadados->mimeType);
            ++$resumo->enfileirados;
        } catch (ArquivoNaoEncontrado) {
            ++$resumo->locaisAusentes;
        } catch (\Throwable) {
            // Sem a mensagem: a do disco carrega o caminho, e o caminho carrega o nome do arquivo.
            ++$resumo->erros;
        }
    }
}

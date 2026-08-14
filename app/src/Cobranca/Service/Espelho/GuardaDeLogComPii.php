<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 🔴 Impede que um comando do espelho rode com o log de SQL ligado — porque esse log imprime
 * **CPF, e-mail e telefone de condômino** na saída do terminal.
 *
 * ## O incidente que originou esta classe (13/08/2026)
 *
 * Uma carga rodou com `APP_ENV=prod` mas **sem** `APP_DEBUG=0`. O `.env` do projeto traz
 * `APP_DEBUG=1`, então o middleware de depuração do Doctrine continuou ligado e cada `INSERT` saiu na
 * tela com os parâmetros por extenso:
 *
 * ```
 * INSERT INTO cobranca_relatorio_linha (...) VALUES (?, ...) (parameters: array{
 *   "4":"<NOME DO CONDÔMINO>",
 *   "18":"[\"<UNIDADE>\",...,\"000.000.000-00\",...,\"fulano@example.com\",\"+55 (61) 90000-0000\"]"})
 * ```
 *
 * ⚠️ **O exemplo acima é SINTÉTICO, e tem de continuar sendo.** A primeira versão deste docblock
 * trazia o dump real, com nome, CPF válido, e-mail e telefone de um condômino — colado do log de
 * produção enquanto se documentava a regra que existe para impedir exatamente isso. A quarta revisão
 * pegou. Dado de exemplo aqui é `000.000.000-00` e `example.com` (RFC 2606), nunca o de verdade:
 * documentar o vazamento não autoriza reproduzi-lo.
 *
 * **Por que é grave e não é nota de rodapé:** o dono roda estes comandos na VPS e **cola a saída em
 * chat** para o Claude conferir. Um dump desses transporta dado pessoal de centenas de condôminos
 * para fora do sistema, num canal que ninguém desenhou para isso. O relatório de dados cadastrais,
 * que entrou no espelho nesta fatia, é justamente o que mais concentra PII.
 *
 * ## Como a guarda decide
 *
 * Dispara com **debug ligado fora do ambiente de teste**. A exceção do `test` é deliberada e tem
 * razão, não é conveniência: lá a saída vai para um buffer de asserção, o DAMA reverte tudo, e os
 * dados são fabricados pelo Foundry — não há PII real nem humano lendo terminal.
 *
 * A recusa é **alta e com o comando pronto para copiar**, e não um aviso que rola para fora da tela
 * junto com o resto. Quem realmente precisar do log verboso passa `--aceito-log-com-pii`, que existe
 * para depuração local e diz no nome o que está aceitando.
 */
final class GuardaDeLogComPii
{
    /** Código de saída próprio — na faixa `6x`, como os outros comandos do espelho. */
    public const LOG_COM_PII = 69;

    public function __construct(
        private readonly bool $debug,
        private readonly string $ambiente,
    ) {
    }

    /**
     * @return bool `true` quando o comando deve PARAR (a mensagem já foi impressa)
     */
    public function bloqueia(SymfonyStyle $io, bool $aceitouExplicitamente, string $comando): bool
    {
        if (!$this->vaiDespejarLog() || $aceitouExplicitamente) {
            return false;
        }

        $io->error(
            'O log de SQL do Doctrine está LIGADO (APP_DEBUG=1). Ele imprime os parâmetros de cada '
            . 'consulta — o que inclui CPF, e-mail e telefone de condômino vindos do relatório de '
            . 'dados cadastrais. Nada foi lido nem gravado.'
        );

        $io->text('Rode assim, com o log desligado:');
        $io->text(sprintf(
            "\n    <info>APP_DEBUG=0 php -d memory_limit=512M bin/console %s --env=prod ...</info>\n",
            $comando,
        ));
        $io->text(
            'Se estiver depurando localmente e QUISER o log com os dados por extenso, passe '
            . '<comment>--aceito-log-com-pii</comment> — e não cole essa saída em lugar nenhum.'
        );

        return true;
    }

    /**
     * ⚠️ Público para o teste conseguir afirmar a regra sem montar um comando inteiro. O `test` sai de
     * fora porque lá a saída é buffer de asserção com dado fabricado — ver o docblock da classe.
     */
    public function vaiDespejarLog(): bool
    {
        return $this->debug && $this->ambiente !== 'test';
    }
}

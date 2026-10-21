<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\DTO\ConteudoLido;
use App\Central\DTO\DespachoOutput;
use App\Central\DTO\MensagemOutput;
use App\Central\DTO\RespostaOutput;
use App\Central\Entity\Mensagem;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Entity\Auth\User;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * O PONTO ÚNICO por onde o texto de uma mensagem da Central sai para alguém (regra 13 da Central,
 * `docs/specs/encaminhar-via-chat-ia.md` §3). Toda `MensagemOutput` e toda citação (`RespostaOutput`) nascem aqui.
 *
 * Por quê: o despacho grava no texto o título, um trecho do teor e o NUP da pasta. Quem entra num grupo depois (ou
 * perde o acesso à pasta) leria isso no histórico. Por isso, numa mensagem com `despacho_tipo`, a pasta é conferida
 * NA LEITURA: quem lê precisa vê-la (`AcessoAPasta::podeVer`; no registro do Financeiro, também
 * `VisibilidadeDoFinanceiroDaPasta::podeVer`). Pasta apagada (`NULL`), excluída (lápide) ou de outro escritório conta
 * como "não vê". Quem não vê recebe "Item de pasta sem acesso", sem a referência.
 *
 * A pasta é buscada com o escritório da mensagem explícito (regra 4), não pelo proxy da associação.
 *
 * Memória: uma resposta por (leitor, escritório, pasta) durante UMA leitura (o histórico, o polling, a lista). Quem
 * chama guarda o `$memoria` só durante aquela leitura, como em `AcessoAosAvisos`: o serviço é compartilhado entre
 * pedidos, e um cache aqui sobreviveria a uma revogação.
 */
final class LeituraDasMensagens
{
    /** O que sai no lugar do texto do despacho para quem não vê a pasta. */
    public const SEM_ACESSO = 'Item de pasta sem acesso';

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
    ) {
    }

    /**
     * Várias mensagens para o mesmo leitor, com uma memória só.
     *
     * @param list<Mensagem> $mensagens
     *
     * @return list<MensagemOutput>
     */
    public function paraLeitor(array $mensagens, User $leitor): array
    {
        // Laço, não `array_map` com arrow fn: a arrow fn copiaria a memória a cada chamada.
        $memoria = [];
        $saidas = [];
        foreach ($mensagens as $mensagem) {
            $saidas[] = $this->saida($mensagem, $leitor, $memoria);
        }

        return $saidas;
    }

    /** Uma mensagem só (a que acabou de ser enviada). */
    public function umaParaLeitor(Mensagem $mensagem, User $leitor): MensagemOutput
    {
        $memoria = [];

        return $this->saida($mensagem, $leitor, $memoria);
    }

    /**
     * A mensagem e a citação dela, cada uma pela sua própria conferência: a resposta a um despacho que o leitor não vê
     * mostra o trecho citado como "Item de pasta sem acesso".
     *
     * @param array<string, bool|Pasta|null> $memoria
     */
    public function saida(Mensagem $mensagem, User $leitor, array &$memoria): MensagemOutput
    {
        $citada = $mensagem->getRespostaA();

        return MensagemOutput::daLeitura(
            $mensagem,
            $leitor,
            $this->conteudo($mensagem, $leitor, $memoria),
            $citada !== null ? RespostaOutput::daLeitura($citada, $leitor, $this->conteudo($citada, $leitor, $memoria)) : null,
        );
    }

    /**
     * O texto que este leitor pode ver desta mensagem.
     *
     * @param array<string, bool|Pasta|null> $memoria
     */
    public function conteudo(Mensagem $mensagem, User $leitor, array &$memoria): ConteudoLido
    {
        if (!$mensagem->isDespacho()) {
            return ConteudoLido::liberado($mensagem->getConteudo());
        }

        $pasta = $this->pastaQueOLeitorVe($mensagem, $leitor, $memoria);
        if ($pasta === null) {
            return ConteudoLido::oculto(self::SEM_ACESSO);
        }

        $nup = trim((string) $pasta->getNup());

        return ConteudoLido::liberado($mensagem->getConteudo(), new DespachoOutput((int) $pasta->getId(), $nup !== '' ? $nup : null));
    }

    /** @param array<string, bool|Pasta|null> $memoria */
    private function pastaQueOLeitorVe(Mensagem $mensagem, User $leitor, array &$memoria): ?Pasta
    {
        $pastaId = $mensagem->getDespachoPastaId();
        if ($pastaId === null) {
            return null;   // a pasta foi apagada (ON DELETE SET NULL): ninguém vê
        }

        $tenant = $mensagem->getTenant();
        $chave = sprintf('%d:%d:%d', (int) $leitor->getId(), (int) $tenant->getId(), $pastaId);

        if (!array_key_exists('pasta:' . $chave, $memoria)) {
            $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
            $memoria['pasta:' . $chave] = $pasta instanceof Pasta && !$pasta->estaExcluida() && $this->acessoAPasta->podeVer($leitor, $pasta)
                ? $pasta
                : null;
        }
        $pasta = $memoria['pasta:' . $chave];
        if (!$pasta instanceof Pasta) {
            return null;
        }

        if ($mensagem->getDespachoTipo() === TipoDeItemEncaminhado::RegistroFinanceiro) {
            $memoria['financeiro:' . $chave] ??= $this->financeiro->podeVer($leitor, $tenant, $pasta);
            if ($memoria['financeiro:' . $chave] !== true) {
                return null;
            }
        }

        return $pasta;
    }
}

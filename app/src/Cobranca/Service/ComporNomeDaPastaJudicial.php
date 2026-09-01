<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\CasoCobranca;

/**
 * Monta o nome do cliente da pasta que a judicialização abre, no padrão do escritório
 * (decisão do dono, 2026-09-01):
 *
 *     <nome fantasia do cliente da carteira> - <nome da pessoa cobrada>
 *     APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ
 *
 * Por que existe: até 2026-08-27 a pasta nascia só com o nome da pessoa cobrada
 * (`docs/specs/cobranca-judicializar-cria-pasta.md` §2.1) e o escritório vinha digitando o prefixo à
 * mão — duas das três pastas judiciais de produção já se chamavam `APLC TOP LIFE 1 - <NOME>`, e a
 * terceira escapou. O padrão passa a ser montado pelo sistema; o campo continua EDITÁVEL no modal,
 * então isto é uma sugestão forte, não uma trava.
 *
 * O prefixo mora no CLIENTE da carteira, não na carteira: `caso → objeto → carteira → cliente`. Só
 * cliente PJ tem nome fantasia — para PF (ou PJ sem fantasia preenchida) o nome cai para a pessoa
 * sozinha, que é exatamente o comportamento anterior. Nunca se inventa prefixo a partir da razão
 * social: ela tem 93 caracteres no maior caso de produção e viraria um nome de pasta ilegível.
 *
 * ⚠️ Depende do nome fantasia CADASTRADO. Se o cadastro do cliente trouxer pontuação (o `APLC - TOP
 * LIFE 1` que havia em produção até esta frente), ela entra no nome da pasta como está — o conserto
 * é no cadastro do cliente, não aqui.
 */
final class ComporNomeDaPastaJudicial
{
    /**
     * Limite da coluna `pasta.nome_cliente`. Estourá-lo derrubaria a criação da pasta no flush, com
     * um erro de banco no lugar de um nome de pasta.
     */
    private const LIMITE = 255;

    /** O separador do padrão, com espaços — é como o escritório já escrevia à mão. */
    private const SEPARADOR = ' - ';

    /**
     * O nome do cliente da pasta, ou `null` quando não há pessoa cobrada de quem partir (caso em que
     * o modal abre com o campo vazio, como antes).
     */
    public function paraCaso(CasoCobranca $caso): ?string
    {
        $nomeDaPessoa = trim($caso->getPessoaCobradaAtual()?->getNome() ?? '');

        // Sem pessoa não há o que compor: devolver só o prefixo produziria `APLC TOP LIFE 1 - ` —
        // um nome de pasta que parece certo e está pela metade.
        if ($nomeDaPessoa === '') {
            return null;
        }

        $prefixo = $this->nomeFantasiaDoClienteDaCarteira($caso);

        if ($prefixo === null) {
            return $nomeDaPessoa;
        }

        $composto = $prefixo . self::SEPARADOR . $nomeDaPessoa;

        // Fantasia e nome da pessoa têm 255 cada; juntos cabem 513. No estouro vale o nome da pessoa
        // inteiro: nome cortado no meio é pior que nome curto, e o gestor vê o campo antes de criar.
        return mb_strlen($composto) > self::LIMITE ? $nomeDaPessoa : $composto;
    }

    /**
     * A fantasia do cliente da carteira, ou `null` quando não há prefixo a aplicar — carteira sem
     * objeto, objeto sem carteira, cliente PF, ou PJ com a fantasia em branco.
     */
    private function nomeFantasiaDoClienteDaCarteira(CasoCobranca $caso): ?string
    {
        $cliente = $caso->getObjeto()?->getCarteira()?->getCliente();

        if (!$cliente instanceof ClientePJ) {
            return null;
        }

        $fantasia = trim($cliente->getNomeFantasia() ?? '');

        return $fantasia === '' ? null : $fantasia;
    }
}

<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\CasoCobranca;

/**
 * Monta o nome do cliente da pasta que a judicialização abre: o CLIENTE DA CARTEIRA, sozinho
 * (decisão do dono, 2026-10-27, que substitui o padrão `<fantasia> - <pessoa cobrada>` de 2026-09-01):
 *
 *     APLC TOP LIFE 1
 *
 * Por que mudou: a pasta judicial representa o credor, e a pessoa cobrada já aparece no processo e
 * no caso. Com a pessoa no nome, duas pastas do mesmo credor nunca se agrupavam na ordenação e o
 * Expediente exibia o devedor onde o escritório procura o cliente.
 *
 * O cliente mora na carteira, não no caso: `caso → objeto → carteira → cliente`. Nome fantasia
 * primeiro; sem ele, a razão social (PJ) ou o nome completo (PF). O campo segue EDITÁVEL no modal,
 * então isto é uma sugestão forte, não uma trava.
 *
 * ⚠️ Depende do cadastro do cliente: pontuação no nome fantasia entra no nome da pasta como está.
 */
final class ComporNomeDaPastaJudicial
{
    /** Limite da coluna `pasta.nome_cliente`; fantasia, razão social e nome completo também têm 255. */
    private const LIMITE = 255;

    /**
     * O nome do cliente da pasta, ou `null` quando o caso não tem cliente de carteira de quem partir
     * (o modal abre com o campo vazio, como antes).
     */
    public function paraCaso(CasoCobranca $caso): ?string
    {
        $cliente = $caso->getObjeto()?->getCarteira()?->getCliente();

        return match (true) {
            $cliente instanceof ClientePJ => $this->compor($cliente->getNomeFantasia(), $cliente->getRazaoSocial()),
            $cliente instanceof ClientePF => $this->compor(null, $cliente->getNomeCompleto()),
            default => null,
        };
    }

    /**
     * A regra, a partir dos valores soltos. Existe além do {@see self::paraCaso()} porque a listagem
     * resolve o identificador de uma página inteira de pastas numa consulta só e recebe COLUNAS, não
     * entidades. Duplicar a regra nos dois lugares seria a receita para a tela e o modal divergirem.
     *
     * @param ?string $nomeFantasia nome fantasia do cliente PJ
     * @param ?string $nomeDeRegistro razão social (PJ) ou nome completo (PF): a queda do fantasia
     */
    public function compor(?string $nomeFantasia, ?string $nomeDeRegistro): ?string
    {
        foreach ([$nomeFantasia, $nomeDeRegistro] as $nome) {
            $nome = trim((string) $nome);

            if ($nome !== '') {
                return mb_substr($nome, 0, self::LIMITE);
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Consentimento\ConfirmacaoDeEnderecoInterface;
use App\Comercial\DTO\CadastroDeContatosInput;
use App\Comercial\DTO\ContatosCadastradosOutput;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Entity\Auth\User;

/**
 * "Cadastrar e-mail" da Central de envios em modo venda (F3h; spec comercial-e-emails §10; desenho 05 VS 21.10.26,
 * `cmLeadAdd` L644). O Master informa contatos que autorizaram receber; ninguém entra direto na Base de contatos:
 * cada endereço recebe o pedido de confirmação dupla da lista `comercial_campanhas` (§3.3) e só entra quando
 * confirmar. Os limitadores são os da confirmação (1 pedido por endereço a cada 24 h, ou 30 dias para quem saiu da
 * lista; teto global de 200 por hora); o limite por IP é dos formulários públicos e não se aplica a esta rota do
 * Master (ver o relatório da F3h).
 *
 * A confirmação não revela o que fez (é silenciosa para os formulários públicos). Para o Master contar o resultado:
 * já confirmado e na lista = "já existiam" (não pede de novo); o fato `pedido` gravado agora = pedido enviado; sem
 * fato novo = barrado por um limite. Endereço inválido também conta como barrado.
 */
final class CadastrarContatoDoMasterUseCase
{
    public const ORIGEM = 'cadastro-manual-master';
    public const VERSAO = 'cadastro-manual-master-v1';
    /** O que o fato `pedido` guarda: a declaração do Master que o dc pede no campo (L644). */
    public const TEXTO = 'Cadastro manual pelo Master do BlueJus, que declarou ter a autorização do contato para receber '
        . 'as campanhas por e-mail. Só cadastre quem autorizou receber (LGPD).';

    public function __construct(
        private readonly ConfirmacaoDeEnderecoInterface $confirmacao,
        private readonly FatoDeConsentimentoRepository $fatos,
    ) {
    }

    public function executar(CadastroDeContatosInput $input, User $master): ContatosCadastradosOutput
    {
        if (!\in_array('ROLE_SUPER_ADMIN', $master->getRoles(), true)) {
            throw new CampanhaRecusada('Só o Master cadastra contatos.');
        }

        $pedidos = 0;
        $jaExistiam = 0;
        $barrados = \count($input->invalidos);
        foreach ($input->emails as $email) {
            if ($this->confirmacao->estaConfirmado(ComposicaoDaCampanha::LISTA, $email)) {
                ++$jaExistiam;
                continue;
            }
            $this->pedirConfirmacao($email) ? ++$pedidos : ++$barrados;
        }

        return new ContatosCadastradosOutput($pedidos, $jaExistiam, $barrados);
    }

    /** Pede a confirmação e diz se o pedido saiu (um fato `pedido` novo) ou se um limite o segurou. */
    private function pedirConfirmacao(string $email): bool
    {
        $antes = $this->fatos->ultimoPedidoEm(null, ComposicaoDaCampanha::LISTA, $email);
        try {
            $this->confirmacao->pedir(ComposicaoDaCampanha::LISTA, $email, self::ORIGEM, self::VERSAO, self::TEXTO);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $depois = $this->fatos->ultimoPedidoEm(null, ComposicaoDaCampanha::LISTA, $email);

        return $depois !== null && ($antes === null || $depois > $antes);
    }
}

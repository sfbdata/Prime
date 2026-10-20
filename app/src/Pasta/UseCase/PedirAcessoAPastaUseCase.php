<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\DTO\PedidoDeAcessoOutput;
use App\Pasta\DTO\PedirAcessoInput;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PedidoDeAcessoAPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\AvisosDoCadeado;
use Psr\Clock\ClockInterface;

/**
 * "Pedir acesso ao primário" da tela "Pasta trancada" e "Pedir para editar" da faixa de somente
 * visualização (spec pasta-cadeado K5; desenho L1282-1296 e `pedirAcessoPasta`). Substitui o antigo
 * `PedirAcessoAPastaTrancadaUseCase`, que só mandava a notificação.
 *
 * História: a pessoa abre a pasta e encontra o cadeado. Escreve um motivo, se quiser, e pede. Cada
 * primário ativo e o Master do escritório recebem o aviso, com o link para a pasta, onde decidem.
 *
 * Regras: só pede quem veria a pasta se ela estivesse livre (a permissão de ver, sem o teto do cadeado) e
 * está abaixo do nível pedido (quem já edita não pede nada); fica um
 * pedido pendente por pasta e pessoa; pedir de novo dentro de 24 h é recusado com o texto do desenho;
 * depois de 24 h o pendente é renovado; recusado ou liberado, a pessoa pode pedir de novo na hora.
 * Pasta sem cadeado não tem o que pedir.
 */
final class PedirAcessoAPastaUseCase
{
    public function __construct(
        private readonly PedidoDeAcessoAPastaRepository $pedidos,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly AvisosDoCadeado $avisos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(PedirAcessoInput $input): PedidoDeAcessoOutput
    {
        $this->exigirQuePodePedir($input);
        $agora    = $this->relogio->now();
        $pendente = $this->pedidos->pendenteDe($input->pasta, $input->solicitante);

        if ($pendente !== null) {
            $pendente->renovar($input->nivel, $input->motivo, $agora);
            $pedido = $pendente;
        } else {
            $pedido = new PedidoDeAcessoAPasta($input->tenant, $input->pasta, $input->solicitante, $input->nivel, $input->motivo, $agora);
        }

        $this->pedidos->salvar($pedido);
        $avisados = $this->avisos->avisarPedido($pedido);
        // Grava o pedido mesmo sem ninguém para avisar, e o corpo (motivo) dos avisos.
        $this->pedidos->salvar($pedido, flush: true);

        return PedidoDeAcessoOutput::fromEntity($pedido, $avisados);
    }

    private function exigirQuePodePedir(PedirAcessoInput $input): void
    {
        if ($input->pasta->getTenant() !== $input->tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->veriaSemCadeado($input->solicitante, $input->pasta)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }
        if ($this->acessoAPasta->cadeadoDe($input->pasta) === null) {
            throw new \DomainException('Esta pasta não tem cadeado.');
        }
        if (!$input->nivel->podeVer()) {
            throw new \DomainException('O pedido é para visualizar ou para editar.');
        }

        $atual = $this->acessoAPasta->nivel($input->solicitante, $input->pasta);
        if ($atual === NivelDeAcesso::Editar || ($atual === NivelDeAcesso::Visualizar && $input->nivel === NivelDeAcesso::Visualizar)) {
            throw new \DomainException('Você já tem esse acesso a esta pasta.');
        }
    }
}

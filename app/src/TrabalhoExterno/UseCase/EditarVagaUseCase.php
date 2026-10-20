<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Editar vaga" e "Ajustar antes" da Central; "Editar pedido" (pendente) e "Corrigir e reenviar"
 * (reprovada, volta para a aprovação) do dono externo (desenho 06 l.1005 e l.1095). O pedido
 * reenviado avisa a Central (spec §4.7).
 */
final class EditarVagaUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly EntityManagerInterface $em,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, DadosDaVaga $dados): Vaga
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        if (!$this->politica->podeEditar($ctx, $vaga)) {
            throw new AcaoNaoPermitida('Você não pode editar esta vaga.');
        }
        $dados->exigirCompleta();
        $peloMaster = $ctx->perfil === Perfil::Master;
        if (!$peloMaster) {
            $dados->limitarParaQuemNaoEMaster($ctx->configuracao->getVagas()['maxDias']);
        }

        $antes = $vaga->getSituacao();
        $vaga->editar($dados, $ctx->autor()->nome, $peloMaster, $this->politica->ehDaCentral($ctx), $agora);
        if ($antes === SituacaoVaga::Reprovada && $vaga->getSituacao() === SituacaoVaga::Pendente) {
            $this->avisos->pedidoDeDivulgacao($ctx, $vaga, true);
        }
        $padrao = $this->preferencias->obter($ctx->usuario, PreferenciaDoUsuario::OFERTANTE_PADRAO);
        $padrao->definir($dados->ofertante, $agora);
        $this->preferencias->salvar($padrao);
        $this->em->flush();

        return $vaga;
    }
}

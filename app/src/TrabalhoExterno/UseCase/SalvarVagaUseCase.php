<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Nova vaga" e "Divulgar uma vaga na Central" (`salvarVaga`, desenho 06 l.1005).
 * Quem: a Central (Master, ou Equipe com `talentos`) publica na hora; o externo (Solicitante ou
 * Convidado) envia para a Central aprovar. Só o Master escolhe o tipo (vaga comum ou oferta com
 * tempo) e "Até a vaga ser preenchida"; os demais ficam no teto de dias da configuração.
 * O "QUEM ESTÁ OFERECENDO" vira o padrão das próximas vagas desse usuário.
 * Avisos (spec §4.7): o pedido vai para a Central aprovar; a vaga publicada pela Central, para quem
 * ligou o aviso da área. O aviso precisa do código, então a vaga é gravada antes, na mesma transação.
 */
final class SalvarVagaUseCase
{
    public function __construct(
        private readonly PoliticaDaVaga $politica,
        private readonly VagaRepository $vagas,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly EntityManagerInterface $em,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    public function executar(Contexto $ctx, DadosDaVaga $dados): Vaga
    {
        $dados->exigirCompleta();
        if ($ctx->perfil !== Perfil::Master) {
            $dados->limitarParaQuemNaoEMaster($ctx->configuracao->getVagas()['maxDias']);
        }
        $agora = new \DateTimeImmutable();
        $quem = $ctx->autor()->nome;
        $vaga = match (true) {
            $this->politica->podeCriar($ctx) => Vaga::daCentral($ctx->usuario, $quem, $dados, $agora),
            $this->politica->podeDivulgar($ctx) => Vaga::doExterno($ctx->perfil === Perfil::Convidado ? null : $ctx->tenant, $ctx->usuario, $quem, $dados, $agora),
            default => throw new AcaoNaoPermitida('Você não pode publicar vagas.'),
        };
        $this->em->wrapInTransaction(function () use ($ctx, $dados, $vaga, $agora): void {
            $this->vagas->salvar($vaga);
            $this->guardarOfertante($ctx, $dados, $agora);
            $this->em->flush();
            $this->avisar($ctx, $vaga, $agora);
        });

        return $vaga;
    }

    private function avisar(Contexto $ctx, Vaga $vaga, \DateTimeImmutable $agora): void
    {
        $vaga->ehDaCentral()
            ? $this->avisos->vagaNaArea($ctx, $vaga, $agora)
            : $this->avisos->pedidoDeDivulgacao($ctx, $vaga, false);
    }

    private function guardarOfertante(Contexto $ctx, DadosDaVaga $dados, \DateTimeImmutable $agora): void
    {
        $padrao = $this->preferencias->obter($ctx->usuario, PreferenciaDoUsuario::OFERTANTE_PADRAO);
        $padrao->definir($dados->ofertante, $agora);
        $this->preferencias->salvar($padrao);
    }
}

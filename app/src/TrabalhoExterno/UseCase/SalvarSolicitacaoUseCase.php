<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ArmazenadorDeDocumentos;
use App\TrabalhoExterno\Service\Contexto;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Nova solicitação de trabalho externo": salvar rascunho ou enviar.
 *
 * Quem: o Solicitante (usuário de escritório que não é a sede, com a permissão do módulo).
 * Objetivo: pedir à Central um serviço em outra localidade, ou guardar o pedido para terminar depois.
 * Pré-condições: escritório escolhido; para continuar um rascunho, ele é do mesmo escritório.
 * Fluxo: valida (no envio, também os obrigatórios *), grava o formulário e os documentos; no envio
 * a demanda vira "Nova solicitação" e entra no histórico.
 * Alternativos: central suspensa com "aceitar só rascunhos" bloqueia o envio; rascunho de outro
 * escritório é "não encontrado"; demanda já enviada não é editável.
 * Pós-condição: a demanda pertence ao escritório ATUAL (o seletor de escritório do modal só
 * preenche os dados do solicitante).
 */
final class SalvarSolicitacaoUseCase
{
    public function __construct(
        private readonly DemandaExternaRepository $repositorio,
        private readonly ArmazenadorDeDocumentos $documentos,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param list<UploadedFile> $arquivos
     */
    public function executar(Contexto $ctx, DadosDaSolicitacao $dados, bool $enviar, ?string $codigoDoRascunho, array $arquivos = []): DemandaExterna
    {
        if ($ctx->perfil !== Perfil::Solicitante || $ctx->tenant === null) {
            throw new AcaoNaoPermitida('Só o escritório solicitante abre solicitações.');
        }

        $grupos = $enviar ? ['Default', 'envio'] : ['Default'];
        $violacoes = $this->validator->validate($dados, null, $grupos);
        if (count($violacoes) > 0) {
            throw new DadosInvalidos('Preencha os campos obrigatórios marcados com *.');
        }
        if ($enviar && $ctx->configuracao->envioBloqueado()) {
            throw new DadosInvalidos('Central suspensa: salve como rascunho para enviar depois.');
        }

        $demanda = $codigoDoRascunho !== null
            ? $this->rascunhoDoEscritorio($ctx, $codigoDoRascunho)
            : new DemandaExterna($ctx->tenant, $ctx->usuario);

        $demanda->preencher($dados);
        $this->repositorio->salvar($demanda);
        $this->documentos->anexar($demanda, $arquivos, $ctx->autor());

        if ($enviar) {
            $demanda->enviar($ctx->autor());
        }

        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }

    private function rascunhoDoEscritorio(Contexto $ctx, string $codigo): DemandaExterna
    {
        $id = DemandaExterna::idDoCodigo($codigo);
        $demanda = $id !== null && $ctx->tenant !== null ? $this->repositorio->doEscritorioPorId($id, $ctx->tenant) : null;

        if ($demanda === null) {
            throw new DemandaNaoEncontrada();
        }
        if ($demanda->getSituacao() !== Situacao::Rascunho) {
            throw new DadosInvalidos('Esta solicitação já foi enviada e não pode mais ser editada.');
        }

        return $demanda;
    }
}

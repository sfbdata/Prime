<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * "Salvar currículo" do externo (`salvarCv`, desenho 06 l.1006): cria o dele na primeira vez e edita
 * nas seguintes. Um por usuário: dois primeiros envios ao mesmo tempo esbarram no índice único e o
 * segundo recebe 409. O anexo de bloco que a nova situação esconde sai depois do COMMIT. O interno não
 * tem currículo próprio e a prévia do Master não grava.
 */
final class SalvarMeuCurriculoUseCase
{
    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly CurriculoRepository $repositorio,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(Contexto $ctx, DadosDoCurriculo $dados): Curriculo
    {
        $this->exigirExterno($ctx);
        $dados->exigirValido();
        $agora = new \DateTimeImmutable();

        $cv = $this->repositorio->doUsuario($ctx->usuario);
        $descartados = [];
        if ($cv === null) {
            $cv = Curriculo::criar($ctx->usuario, $dados, $agora);
        } else {
            $descartados = $cv->editar($dados, $agora);
        }
        $this->gravar($cv);

        if ($descartados !== []) {
            $this->remocao->remover(array_map(ChavesDeTrabalhoExterno::arquivoDoCurriculoPorNome(...), $descartados), 'trabalho_externo_curriculo_bloco_descartado');
        }

        return $cv;
    }

    private function gravar(Curriculo $cv): void
    {
        try {
            $this->repositorio->salvar($cv, flush: true);
        } catch (UniqueConstraintViolationException) {
            throw new TransicaoInvalida('Seu currículo já foi salvo. Atualize a página.');
        }
    }

    private function exigirExterno(Contexto $ctx): void
    {
        if (!$this->politica->podeEscreverOMeu($ctx)) {
            throw new AcaoNaoPermitida($ctx->previa
                ? 'Na pré-visualização não é possível alterar nada.'
                : '"Meu currículo" é de quem procura vaga: a Central não tem currículo próprio.');
        }
    }
}

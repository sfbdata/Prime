<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\MeuCurriculo;

/** "Remover foto" e a troca de anexo pelo dono: o registro sai primeiro, o arquivo depois do COMMIT. */
final class RemoverArquivoDoCurriculoUseCase
{
    public function __construct(
        private readonly MeuCurriculo $meu,
        private readonly CurriculoRepository $repositorio,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(Contexto $ctx, TipoArquivoDoCurriculo $tipo): Curriculo
    {
        $cv = $this->meu->paraEscrever($ctx, 'Salve o currículo antes de anexar arquivos.');
        $anterior = $cv->removerArquivo($tipo, new \DateTimeImmutable());
        if ($anterior === null) {
            return $cv;
        }

        $this->repositorio->salvar($cv, flush: true);
        $this->remocao->remover([ChavesDeTrabalhoExterno::arquivoDoCurriculoPorNome($anterior)], 'trabalho_externo_curriculo_arquivo_removido');

        return $cv;
    }
}

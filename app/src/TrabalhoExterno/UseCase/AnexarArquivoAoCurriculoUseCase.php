<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\ArmazenadorDeArquivosDoCurriculo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\MeuCurriculo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Foto, carteira da OAB, comprovante do curso ou arquivo do currículo (spec VS 18.10.26 §4.5,
 * decisão 5). Só no currículo já salvo: não existe rascunho sem o consentimento da LGPD. O arquivo
 * novo é gravado antes do COMMIT (e sai se a transação falhar); o que ele substitui sai depois.
 */
final class AnexarArquivoAoCurriculoUseCase
{
    public function __construct(
        private readonly MeuCurriculo $meu,
        private readonly ArmazenadorDeArquivosDoCurriculo $armazenador,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(Contexto $ctx, TipoArquivoDoCurriculo $tipo, ?UploadedFile $arquivo): Curriculo
    {
        $cv = $this->meu->paraEscrever($ctx, 'Salve o currículo antes de anexar arquivos.');
        if ($arquivo === null) {
            throw new DadosInvalidos('Nenhum arquivo enviado.');
        }

        $gravado = $this->armazenador->gravar($tipo, $arquivo);
        $anterior = $cv->anexarArquivo($tipo, $gravado['chave'], $gravado['nome'], new \DateTimeImmutable());
        $this->transacao->confirmar(static fn (): array => [ChavesDeTrabalhoExterno::arquivoDoCurriculoPorNome($gravado['chave'])], 'trabalho_externo_curriculo_arquivo');

        if ($anterior !== null) {
            $this->remocao->remover([ChavesDeTrabalhoExterno::arquivoDoCurriculoPorNome($anterior)], 'trabalho_externo_curriculo_arquivo_substituido');
        }

        return $cv;
    }
}

<?php
declare(strict_types=1);
namespace App\Profile\UseCase;

use App\Profile\Armazenamento\ChavesDePerfil;
use App\Profile\DTO\AtualizarFotoInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Http\FonteDeUploadHttp;

final class AtualizarFotoPerfilUseCase
{
    private const MIME_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];
    private const TAMANHO_MAX = 3 * 1024 * 1024;

    public function __construct(
        private readonly UserProfileRepository $repository,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(UserProfile $perfil, AtualizarFotoInput $input): void
    {
        if (!in_array($input->arquivo->getMimeType(), self::MIME_PERMITIDOS, true)) {
            throw new \InvalidArgumentException('Formato de imagem não permitido. Use JPG, PNG ou WebP.');
        }

        if ($input->arquivo->getSize() > self::TAMANHO_MAX) {
            throw new \InvalidArgumentException('A imagem deve ter no máximo 3 MB.');
        }

        // A foto é do User, não do escritório: escopo global (D1).
        $upload     = FonteDeUploadHttp::de($input->arquivo);
        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDePerfil::novaFoto($upload->extensao));
        $novoNome   = $armazenado->chave->nome;

        $fotoAnterior = $perfil->getFotoUrl();
        $perfil->setFotoUrl($novoNome);
        $this->repository->salvar($perfil, flush: true);

        // A anterior só sai depois do COMMIT, e a chave é montada ali, pelo nome guardado: o perfil
        // já aponta para a foto nova. Falha física (ou nome recusado) vira registro, não 500 com a
        // foto nova já salva (E2.5).
        if ($fotoAnterior !== null) {
            $this->remocao->remover(
                [static fn () => ChavesDePerfil::fotoPorNome($fotoAnterior)],
                'AtualizarFotoPerfilUseCase: foto anterior',
            );
        }
    }
}

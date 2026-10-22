<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Armazenamento\ChavesDeComercial;
use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\Campanha\PreparadorDeImagemDaCampanha;
use App\Comercial\DTO\ImagemEnviada;
use App\Comercial\Entity\ImagemDaCampanha;
use App\Comercial\Repository\ImagemDaCampanhaRepository;
use App\Entity\Auth\User;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Psr\Clock\ClockInterface;

/**
 * "Adicionar imagem" / "Trocar imagem" do modo simples (desenho 05 VS 21.10.26 L274 e L659; spec
 * `comercial-e-emails.md` §12, F3i). Só o Master (a rota é `ROLE_SUPER_ADMIN`). A imagem é conferida e regravada pelo
 * {@see PreparadorDeImagemDaCampanha} (tipo pelo conteúdo, 1 MB, 4000 px, sem EXIF), gravada com nome cunhado pelo
 * storage e registrada com um token próprio, válida por 30 dias até ser usada. O arquivo novo só sai se a transação
 * comprovadamente não confirmou (`TransacaoComArquivoNovo`).
 *
 * Trocar a imagem não apaga a anterior: outra campanha ou outro modelo pode usá-la, e a sem uso vence sozinha.
 */
final class EnviarImagemDaCampanhaUseCase
{
    public function __construct(
        private readonly PreparadorDeImagemDaCampanha $preparador,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ImagemDaCampanhaRepository $imagens,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly ImagensDaCampanha $enderecos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(string $bytes, User $master): ImagemEnviada
    {
        $preparada = $this->preparador->preparar($bytes);
        $agora = $this->relogio->now();
        $armazenado = $this->armazenamento->gravar(ChavesDeComercial::novaImagem($preparada->extensao), FonteDeConteudo::deTexto($preparada->bytes));
        $imagem = new ImagemDaCampanha(
            $armazenado->chave->nome,
            $preparada->mime,
            $armazenado->tamanhoBytes,
            $preparada->sha256(),
            $preparada->largura,
            $preparada->altura,
            $master,
            $agora,
            $agora->modify(ImagensDaCampanha::VALIDADE_SEM_USO),
        );
        $this->imagens->adicionar($imagem);
        $this->transacao->confirmar(static fn (): array => [$armazenado->chave], 'comercial_imagem_da_campanha');

        return new ImagemEnviada($imagem->getToken(), (string) $this->enderecos->url($imagem->getToken()));
    }
}

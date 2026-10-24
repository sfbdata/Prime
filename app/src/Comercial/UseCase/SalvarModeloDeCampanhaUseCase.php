<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Salvar modelo" (dc L.551): só o próprio; num fixo, a mensagem do dc. Grava o nome, o assunto, o selo, o título e o
 * conteúdo (o HTML sanitizado de novo). O modo não muda. Auditado (`modelo_upd`, só `{nome, modo}`).
 * F3i (§12): a imagem do modo simples tem de existir e não ter vencido; salva, vale por 1 ano a partir daqui.
 */
final class SalvarModeloDeCampanhaUseCase
{
    public const MENSAGEM = 'Modelo salvo.';
    public const FIXO_NAO_SE_ALTERA = 'Modelos padrão não são alterados. Use Duplicar para criar a sua versão.';

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly ModelosDeCampanha $fixos,
        private readonly SanitizadorDeHtmlDeEmail $sanitizador,
        private readonly ClockInterface $relogio,
        private readonly ImagensDaCampanha $imagens,
    ) {
    }

    public function executar(string $idOuChave, MensagemDaCampanha $m, User $por): ModeloSalvoOutput
    {
        $modelo = $this->proprio($idOuChave);
        if ($m->modo !== $modelo->getModo()) {
            throw new CampanhaRecusada('Modo do modelo inválido.');
        }
        $agora = $this->relogio->now();
        $this->imagens->exigirVigente($m, $agora);
        $html = $m->modo === 'html' ? $this->sanitizador->sanitizar($m->html) : '';
        $modelo->renomear($m->nome);
        $modelo->preencher($m->assunto, $m->selo, $m->titulo, ModeloDaGrade::conteudoParaGravar($m, $html), $agora);
        $this->modelos->salvar($modelo, ModeloDeCampanhaRepository::ALTERADO, $por);
        $this->imagens->renovarPeloModelo($m, $agora);
        $aviso = $m->modo === 'html' && SanitizadorDeHtmlDeEmail::temEstiloEmBloco($m->html) ? ' ' . SanitizadorDeHtmlDeEmail::AVISO_DE_ESTILO : '';

        return new ModeloSalvoOutput(ModeloDaGrade::deEntidade($modelo), self::MENSAGEM . $aviso);
    }

    private function proprio(string $idOuChave): ModeloDeCampanha
    {
        if ($this->fixos->ehFixo($idOuChave)) {
            throw new CampanhaRecusada(self::FIXO_NAO_SE_ALTERA);
        }
        $modelo = ctype_digit($idOuChave) ? $this->modelos->porId((int) $idOuChave) : null;

        return $modelo ?? throw new CampanhaRecusada('Modelo não encontrado.');
    }
}

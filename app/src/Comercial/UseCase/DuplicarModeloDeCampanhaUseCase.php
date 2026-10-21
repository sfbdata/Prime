<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Duplicar" (dc L.550): copia o modelo em edição (com as edições da tela) para um próprio "NOME (cópia)". Os blocos
 * são copiados; o simples é convertido em blocos como o dc (título, texto, notícias com os itens, botão); o HTML
 * importado continua HTML (sanitizado). Gravado e auditado (`modelo_new`).
 *
 * @phpstan-import-type Bloco from \App\Comercial\Campanha\ModelosDeCampanha
 */
final class DuplicarModeloDeCampanhaUseCase
{
    public const MENSAGEM = 'Cópia criada e convertida em blocos. Agora é totalmente ajustável.';

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly SanitizadorDeHtmlDeEmail $sanitizador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(MensagemDaCampanha $base, User $por): ModeloSalvoOutput
    {
        $copia = $base->modo === 'simples' ? self::emBlocos($base) : $base;
        $agora = $this->relogio->now();
        $modelo = new ModeloDeCampanha($base->nome . ' (cópia)', $base->icone, $copia->modo, $por, $agora);
        $html = $copia->modo === 'html' ? $this->sanitizador->sanitizar($copia->html) : '';
        $modelo->preencher($copia->assunto, $copia->selo, $copia->titulo, ModeloDaGrade::conteudoParaGravar($copia, $html), $agora);
        $this->modelos->salvar($modelo, ModeloDeCampanhaRepository::NOVO, $por);

        return new ModeloSalvoOutput(ModeloDaGrade::deEntidade($modelo), self::MENSAGEM);
    }

    /** A conversão do dc L.550: `[titulo, texto] + noticias(itens) + botao(cta, link)`. */
    private static function emBlocos(MensagemDaCampanha $m): MensagemDaCampanha
    {
        /** @var list<Bloco> $blocos */
        $blocos = [['t' => 'titulo', 'a' => $m->titulo, 'b' => ''], ['t' => 'texto', 'a' => $m->corpo, 'b' => '']];
        if ($m->itens !== []) {
            $blocos[] = ['t' => 'noticias', 'a' => implode("\n", $m->itens), 'b' => ''];
        }
        if ($m->cta !== '') {
            $blocos[] = ['t' => 'botao', 'a' => $m->cta, 'b' => $m->link];
        }

        return new MensagemDaCampanha($m->chave, 'blocos', $m->assunto, $m->selo, $m->titulo, blocos: $blocos, nome: $m->nome, icone: $m->icone);
    }
}

<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ImagensDaCampanha;
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
 * "Salvar como meu modelo" num modelo fixo (desenho 05 VS 21.10.26, L653; spec comercial-e-emails.md §10): o Master dá
 * um nome e a cópia entra em "Meus modelos" com o que está na tela, no mesmo modo (simples, blocos ou HTML). O modelo
 * fixo continua guardado. Diferente do "Duplicar", que converte o simples em blocos (dc L.550).
 * F3i (§12): a imagem do modo simples vai junto; tem de existir e não ter vencido, e vale por 1 ano a partir daqui.
 */
final class SalvarComoMeuModeloUseCase
{
    private const TAMANHO_DO_NOME = 40;

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly SanitizadorDeHtmlDeEmail $sanitizador,
        private readonly ClockInterface $relogio,
        private readonly ImagensDaCampanha $imagens,
    ) {
    }

    public function executar(MensagemDaCampanha $base, string $nome, User $por): ModeloSalvoOutput
    {
        $nome = self::nomeValido($nome);
        $agora = $this->relogio->now();
        $this->imagens->exigirVigente($base, $agora);
        $modelo = new ModeloDeCampanha($nome, $base->icone, $base->modo, $por, $agora);
        $html = $base->modo === 'html' ? $this->sanitizador->sanitizar($base->html) : '';
        $modelo->preencher($base->assunto, $base->selo, $base->titulo, ModeloDaGrade::conteudoParaGravar($base, $html), $agora);
        $this->modelos->salvar($modelo, ModeloDeCampanhaRepository::NOVO, $por);
        $this->imagens->renovarPeloModelo($base, $agora);

        return new ModeloSalvoOutput(ModeloDaGrade::deEntidade($modelo), 'Modelo "' . $nome . '" salvo em Meus modelos.');
    }

    private static function nomeValido(string $nome): string
    {
        $nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nome)), 0, self::TAMANHO_DO_NOME);
        if ($nome === '') {
            throw new CampanhaRecusada('Dê um nome ao seu modelo.');
        }

        return $nome;
    }
}

<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Importar HTML" (dc L.553): o arquivo vira um modelo próprio no modo `html`, com o nome e o assunto do `<title>` (ou
 * do nome do arquivo). Até 200 KB; guardado já sanitizado (§8.3), e o aviso de `<style>` quando ele cai. Auditado.
 */
final class ImportarModeloHtmlUseCase
{
    public const MENSAGEM = 'Modelo importado. Use {nome} no HTML para personalizar.';

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly SanitizadorDeHtmlDeEmail $sanitizador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(string $nomeDoArquivo, string $html, User $por): ModeloSalvoOutput
    {
        if (\strlen($html) > SanitizadorDeHtmlDeEmail::LIMITE_EM_BYTES) {
            throw new CampanhaRecusada('O arquivo passa de 200 KB.');
        }
        $limpo = $this->sanitizador->sanitizar($html);
        if (trim(strip_tags($limpo, '<img>')) === '') {
            throw new CampanhaRecusada('O arquivo não tem conteúdo de e-mail que possa ser usado.');
        }
        $titulo = SanitizadorDeHtmlDeEmail::titulo($html);
        $agora = $this->relogio->now();
        $modelo = new ModeloDeCampanha($titulo ?? (string) preg_replace('/\.html?$/i', '', $nomeDoArquivo), 'bi-filetype-html', 'html', $por, $agora);
        $modelo->preencher($titulo ?? 'Informativo', '', '', $limpo, $agora);
        $this->modelos->salvar($modelo, ModeloDeCampanhaRepository::NOVO, $por);
        $aviso = SanitizadorDeHtmlDeEmail::temEstiloEmBloco($html) ? ' ' . SanitizadorDeHtmlDeEmail::AVISO_DE_ESTILO : '';

        return new ModeloSalvoOutput(ModeloDaGrade::deEntidade($modelo), self::MENSAGEM . $aviso);
    }
}

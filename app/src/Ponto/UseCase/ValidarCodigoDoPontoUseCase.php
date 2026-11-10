<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Ponto\Service\CodigoDaFolha;
use App\Ponto\Service\SeloDaFolha;

/**
 * Validação de um código do Ponto (E15, P50 e D20; E16, decisão 17): a janela "Validar código" da /ponto e a página
 * aberta para quem lê o QR Code, sem login e sem escritório. Vale para o comprovante da batida (`BJP-...`) e para a
 * folha do mês emitida em PDF (`BJF-...`).
 *
 * Quem: qualquer pessoa com um código na mão (a própria pessoa, o gestor, quem recebeu o comprovante ou a folha),
 * logada ou não, em qualquer escritório.
 * O que quer: saber se o documento foi emitido pelo BlueJus e se continua como foi emitido.
 * Pré-condição: o segredo do HMAC do Ponto existe; sem ele, nada é autêntico.
 * Fluxo principal: o prefixo diz o que procurar. `BJP` segue para a validação do comprovante da E15, como era.
 * `BJF` procura a folha emitida pelo código (em qualquer escritório: a página é pública), confere o HMAC dos dados
 * assinados com a chave da versão gravada na linha, lê os dados no formato que o leitor conhece e confere que trazem o
 * mesmo código. Devolve o texto da validação: o do comprovante ou "Folha de Ponto MM/AAAA de NOME em dd/mm/aaaa,
 * HH:MM:SS." (desenho L803), que a página mostra debaixo do mesmo "Comprovante autêntico." (L300).
 * Fluxos alternativos: prefixo desconhecido, formato errado, código que não existe, selo que não confere, dados de
 * outro tipo (o aceite usa a mesma chave) ou de outro formato, dados de outro código, selo indisponível. Todos devolvem
 * null, sem dizer qual: quem chama responde "Código não localizado." do mesmo jeito para todos.
 * Pós-condição: nada muda (só leitura).
 * Regras: a página aberta nunca mostra CPF nem localização; da folha, nem a ressalva nem o resumo.
 */
final class ValidarCodigoDoPontoUseCase
{
    private const PREFIXO_DO_COMPROVANTE = 'BJP';

    public function __construct(
        private readonly ValidarComprovanteUseCase $comprovante,
        private readonly FolhaEmitidaDoPontoRepository $folhas,
        private readonly SeloDaFolha $seloDaFolha,
    ) {
    }

    /** @return string|null o texto da validação do código autêntico; null para todo o resto */
    public function executar(string $codigoDigitado): ?string
    {
        $codigo = CodigoDaFolha::normalizar($codigoDigitado);

        return match (substr($codigo, 0, 3)) {
            CodigoDaFolha::PREFIXO       => $this->folhaAutentica($codigo)?->textoDaValidacao(),
            self::PREFIXO_DO_COMPROVANTE => $this->comprovante->executar($codigo)?->textoDaValidacao(),
            default                      => null,
        };
    }

    /** A folha só é autêntica com o selo pronto, o código no formato, a linha no banco e o HMAC conferindo. */
    private function folhaAutentica(string $codigo): ?DadosDaFolhaEmitida
    {
        if (!$this->seloDaFolha->disponivel() || !CodigoDaFolha::temFormato($codigo)) {
            return null;
        }

        $folha = $this->folhas->doCodigo($codigo);
        if ($folha === null || !$this->seloDaFolha->conferir($folha->getDadosAssinados(), $folha->getHmac(), $folha->getVersaoChave())) {
            return null;
        }

        return $this->dadosDoMesmoCodigo($folha->getDadosAssinados(), $codigo);
    }

    /** Os dados selados no formato e no tipo que o leitor conhece, e do mesmo código da linha; senão, null. */
    private function dadosDoMesmoCodigo(string $canonico, string $codigo): ?DadosDaFolhaEmitida
    {
        try {
            $dados = DadosDaFolhaEmitida::deCanonico($canonico);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $dados->codigo === $codigo ? $dados : null;
    }
}

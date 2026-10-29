<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Prompt\PromptDoChatLivre;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;

/**
 * Monta o {@see PedidoDeLinguagem} de uma pergunta do chat: o histórico recente (protegido pelo escritório), a pergunta,
 * os blocos da pasta citada e os arquivos anexados (relidos do armazenamento por chave). O histórico é limitado em
 * número de falas e em caracteres, sempre pelas mais recentes.
 */
final class MontadorDoPedidoDoChat
{
    public const TIPO_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const MAXIMO_DE_FALAS_NO_HISTORICO = 20;
    public const MAXIMO_DE_CARACTERES_NO_HISTORICO = 60_000;

    public function __construct(
        private readonly PromptDoChatLivre $prompt,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ExtratorDeTextoDocx $docx,
        private readonly TetoDeUsoDoChat $teto,
    ) {
    }

    /**
     * @param list<MensagemDeChat> $anteriores as falas anteriores à pergunta, em ordem cronológica
     * @param list<string>         $blocosDaPasta
     * @param list<AnexoDeChat>    $anexos      os da pergunta atual
     * @param list<AnexoDeChat>    $anexosAnteriores os das perguntas anteriores da conversa, em ordem cronológica: os mais
     *                                              recentes que couberem nos limites da leitura seguem junto, para o arquivo
     *                                              continuar no contexto das perguntas seguintes
     *
     * @throws AnexoDoChatInvalidoException
     */
    public function montar(User $eu, Tenant $tenant, ?string $modelo, array $anteriores, string $pergunta, array $blocosDaPasta, array $anexos, array $anexosAnteriores = []): PedidoDeLinguagem
    {
        [$docsAntigos, $textosAntigos] = $this->lerOsAnexos($tenant, $this->quePodemSeguir($anexos, $anexosAnteriores), true);
        [$docsAtuais, $textosAtuais] = $this->lerOsAnexos($tenant, $anexos);
        $documentos = [...$docsAntigos, ...$docsAtuais];
        $textos = [...$textosAntigos, ...$textosAtuais];

        return $this->prompt->montar(
            $this->protecao->rotulo($tenant, self::primeiroNome($eu), ''),
            $this->historico($tenant, $anteriores),
            $this->protecao->livre($tenant, $pergunta),
            $blocosDaPasta,
            $textos,
            $documentos,
            $this->teto->porResposta(),
            $modelo,
        );
    }

    /**
     * Dos anexos antigos, os mais recentes que ainda cabem ao lado dos atuais: no máximo
     * {@see RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE} arquivos e {@see RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_TOTAL}
     * bytes somados (DOCX vira texto e não pesa no pedido nativo, mas conta na quantidade). Os que não couberem ficam só no histórico.
     *
     * @param list<AnexoDeChat> $atuais
     * @param list<AnexoDeChat> $antigos
     *
     * @return list<AnexoDeChat> em ordem cronológica
     */
    private function quePodemSeguir(array $atuais, array $antigos): array
    {
        $vagas = RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE - \count($atuais);
        $bytes = RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_TOTAL - array_sum(array_map(static fn (AnexoDeChat $a): int => $a->getTamanho(), $atuais));
        $seguem = [];
        foreach (array_reverse($antigos) as $anexo) {
            if ($vagas <= 0) {
                break;
            }
            if ($anexo->getTamanho() > $bytes) {
                continue;
            }
            $seguem[] = $anexo;
            --$vagas;
            $bytes -= $anexo->getTamanho();
        }

        return array_reverse($seguem);
    }

    /** @return list<array{papel: string, conteudo: string}> */
    private function historico(Tenant $tenant, array $anteriores): array
    {
        $fala = [];
        $gasto = 0;
        foreach (array_reverse(\array_slice($anteriores, -self::MAXIMO_DE_FALAS_NO_HISTORICO)) as $mensagem) {
            $texto = $this->protecao->livre($tenant, $mensagem->getConteudo());
            $gasto += mb_strlen($texto);
            if ($gasto > self::MAXIMO_DE_CARACTERES_NO_HISTORICO) {
                break;
            }
            array_unshift($fala, ['papel' => $mensagem->getPapel()->value, 'conteudo' => $texto]);
        }

        return $fala;
    }

    /**
     * @param list<AnexoDeChat> $anexos
     * @param bool              $ignorarOsSumidos arquivo de pergunta antiga que saiu do disco é pulado, não derruba a pergunta
     *
     * @return array{0: list<AnexoDeDocumento>, 1: list<array{nome: string, texto: string}>}
     */
    private function lerOsAnexos(Tenant $tenant, array $anexos, bool $ignorarOsSumidos = false): array
    {
        $documentos = [];
        $textos = [];
        foreach ($anexos as $anexo) {
            $nome = $this->protecao->rotulo($tenant, $anexo->getNome(), 'sem nome');
            try {
                $bytes = $this->bytesDe($anexo);
            } catch (AnexoDoChatInvalidoException $e) {
                if ($ignorarOsSumidos) {
                    continue;
                }

                throw $e;
            }
            if ($anexo->getMime() === self::TIPO_DOCX) {
                $textos[] = ['nome' => $nome, 'texto' => $this->protecao->comoDado($tenant, $this->docx->extrair($bytes))];

                continue;
            }
            $documentos[] = new AnexoDeDocumento((int) $anexo->getId(), $nome, $anexo->getMime(), $bytes);
        }

        return [$documentos, $textos];
    }

    private function bytesDe(AnexoDeChat $anexo): string
    {
        try {
            return $this->armazenamento->ler(ChavesDeChatIa::anexo($anexo));
        } catch (ArquivoNaoEncontrado) {
            throw new AnexoDoChatInvalidoException('Um dos arquivos anexados não está mais disponível. Anexe de novo.');
        }
    }

    private static function primeiroNome(User $eu): string
    {
        $nome = trim((string) $eu->getFullName());

        return $nome === '' ? '' : explode(' ', $nome)[0];
    }
}

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
     *
     * @throws AnexoDoChatInvalidoException
     */
    public function montar(User $eu, Tenant $tenant, ?string $modelo, array $anteriores, string $pergunta, array $blocosDaPasta, array $anexos): PedidoDeLinguagem
    {
        [$documentos, $textos] = $this->lerOsAnexos($tenant, $anexos);

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
     *
     * @return array{0: list<AnexoDeDocumento>, 1: list<array{nome: string, texto: string}>}
     */
    private function lerOsAnexos(Tenant $tenant, array $anexos): array
    {
        $documentos = [];
        $textos = [];
        foreach ($anexos as $anexo) {
            $nome = $this->protecao->rotulo($tenant, $anexo->getNome(), 'sem nome');
            $bytes = $this->bytesDe($anexo);
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

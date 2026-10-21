<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\DTO\ItemEncaminhado;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\MencoesDoRegistro;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Twig\NumeroProcessoExtension;

/**
 * O cartão do item encaminhado ("Encaminhar via Chat I.A", `docs/specs/encaminhar-via-chat-ia.md` §3): o SERVIDOR relê
 * o item e monta título e resumo, nunca o texto que veio da tela.
 *
 * Toda leitura vai com o id, a pasta e o escritório explícitos; item ausente, de outra pasta ou de outro escritório dá
 * {@see ItemEncaminhadoNaoEncontradoException} (404), sem dizer qual dos três. Quem chama já conferiu que a pessoa vê
 * a pasta.
 *
 * O texto sai puro (sem HTML) e com todo `@[` desarmado (`@ [`): o título e o resumo entram na mensagem da Central, e o
 * `EnviarMensagemUseCase` transformaria um token de menção copiado do registro em aviso para alguém.
 */
final class ItensEncaminhaveisDaPasta
{
    /** Tamanho do resumo (e do trecho de texto em geral) no cartão do despacho. */
    public const LIMITE_RESUMO = 160;

    /** Tamanho do trecho da nota técnica que vira título. */
    private const LIMITE_TITULO_NOTA = 60;

    public function __construct(
        private readonly PastaMensagemRepository $mensagens,
        private readonly PastaObservacaoDetalhesRepository $observacoesDetalhes,
        private readonly PastaObservacaoFinanceiraRepository $observacoesFinanceiras,
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly ProcessoRepository $processos,
        private readonly TarefaRepository $metas,
        private readonly PastaPrazoRepository $prazos,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly NotaTecnicaRepository $notas,
        private readonly NumeroProcessoExtension $numeroCnj,
    ) {
    }

    /** @throws ItemEncaminhadoNaoEncontradoException */
    public function resumir(Tenant $tenant, Pasta $pasta, TipoDeItemEncaminhado $tipo, ?int $itemId): ItemEncaminhado
    {
        $item = $tipo === TipoDeItemEncaminhado::Pasta
            ? new ItemEncaminhado(self::rotuloDaPasta($pasta), '')
            : $this->doTipo($tenant, $pasta, $tipo, $itemId ?? throw new ItemEncaminhadoNaoEncontradoException());

        return new ItemEncaminhado(self::neutralizarMencoes($item->titulo), self::neutralizarMencoes($item->resumo));
    }

    /**
     * O tipo que decide quem pode ver o item. O cartão do sistema de pró-bono ou de contrato no Registro de Dados é dado
     * financeiro (`RegistroDoSistemaNaPasta::DO_FINANCEIRO`): segue a regra do registro do Financeiro para quem envia,
     * para quem recebe e, gravado na referência do despacho, para quem lê depois. Item ausente fica com o tipo pedido
     * (o `resumir()` dá o 404).
     */
    public function tipoDoAcesso(Tenant $tenant, Pasta $pasta, TipoDeItemEncaminhado $tipo, ?int $itemId): TipoDeItemEncaminhado
    {
        if ($tipo !== TipoDeItemEncaminhado::RegistroDados || $itemId === null) {
            return $tipo;
        }
        $mensagem = $this->mensagens->findByIdAndPastaAndTenant($itemId, $pasta, $tenant);

        return $mensagem !== null && \in_array($mensagem->getSistema(), RegistroDoSistemaNaPasta::DO_FINANCEIRO, true)
            ? TipoDeItemEncaminhado::RegistroFinanceiro
            : $tipo;
    }

    /**
     * O texto de um registro, nota ou publicação como vai para o cartão: cada token `@[Nome](user:ID)` vira "@Nome", sem
     * HTML, entidades decodificadas, brancos colapsados e cortado em `$limite` com reticências.
     */
    public static function textoLimpo(?string $html, int $limite = self::LIMITE_RESUMO): string
    {
        $texto = (string) preg_replace_callback(MencoesDoRegistro::PADRAO, static fn (array $m): string => '@' . $m[1], (string) $html);
        // Quebra de bloco vira espaço, senão "linha um</p><p>linha dois" grudaria.
        $texto = (string) preg_replace('#<br\s*/?>|</(p|li|div|h[1-6]|blockquote|pre)>#i', ' ', $texto);
        $texto = html_entity_decode(strip_tags($texto), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $texto = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $texto));

        return mb_strlen($texto) > $limite ? rtrim(mb_substr($texto, 0, $limite)) . '…' : $texto;
    }

    /** Desarma o começo de token de menção: "@[" vira "@ [", que nenhum padrão de menção reconhece. */
    public static function neutralizarMencoes(string $texto): string
    {
        return str_replace('@[', '@ [', $texto);
    }

    private function doTipo(Tenant $tenant, Pasta $pasta, TipoDeItemEncaminhado $tipo, int $id): ItemEncaminhado
    {
        return match ($tipo) {
            TipoDeItemEncaminhado::RegistroDados => $this->registro($this->mensagens->findByIdAndPastaAndTenant($id, $pasta, $tenant)),
            TipoDeItemEncaminhado::RegistroDetalhes => $this->registro($this->observacoesDetalhes->findByIdAndPastaAndTenant($id, $pasta, $tenant)),
            TipoDeItemEncaminhado::RegistroFinanceiro => $this->registro($this->observacoesFinanceiras->findByIdAndPastaAndTenant($id, $pasta, $tenant)),
            TipoDeItemEncaminhado::Documento => $this->documento($tenant, $pasta, $id),
            TipoDeItemEncaminhado::Secao => $this->secao($tenant, $pasta, $id),
            TipoDeItemEncaminhado::Processo => $this->processo($tenant, $pasta, $id),
            TipoDeItemEncaminhado::Meta => $this->meta($this->metas->findByIdAndPastaAndTenant($id, $pasta, $tenant)),
            TipoDeItemEncaminhado::Prazo => $this->prazo($this->prazos->findDaPasta($id, $pasta, $tenant)),
            TipoDeItemEncaminhado::Push => $this->push($tenant, $pasta, $id),
            TipoDeItemEncaminhado::NotaTecnica => $this->notaTecnica($tenant, $pasta, $id),
            TipoDeItemEncaminhado::Pasta => throw new \LogicException('A pasta é resolvida em resumir().'),
        };
    }

    /** Registro de Dados, Detalhes ou Financeiro: as três entidades têm a mesma forma (autor, data, conteúdo). */
    private function registro(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira|null $registro): ItemEncaminhado
    {
        if ($registro === null) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }

        $autor = $registro->getAutor();
        $quem = $autor instanceof User ? 'Registro de ' . trim((string) $autor->getFullName()) : 'Registro do sistema';

        return new ItemEncaminhado(
            $quem . ' · ' . $registro->getCriadaEm()->format('d/m/Y H:i'),
            self::textoLimpo($registro->getConteudo()),
        );
    }

    private function documento(Tenant $tenant, Pasta $pasta, int $id): ItemEncaminhado
    {
        $documento = $this->documentos->findTodosDaPasta([$id], $pasta, $tenant)[0]
            ?? throw new ItemEncaminhadoNaoEncontradoException();

        return new ItemEncaminhado(self::textoLimpo($documento->getTitulo()), 'Documento');
    }

    private function secao(Tenant $tenant, Pasta $pasta, int $id): ItemEncaminhado
    {
        $secao = $this->secoes->findByIdAndPastaAndTenant($id, $pasta, $tenant)
            ?? throw new ItemEncaminhadoNaoEncontradoException();

        return new ItemEncaminhado(self::textoLimpo($secao->getNome()), 'Seção de documentos');
    }

    private function processo(Tenant $tenant, Pasta $pasta, int $id): ItemEncaminhado
    {
        $processo = $this->processos->findOneByIdDoTenant($id, $tenant);
        if ($processo === null || !$pasta->temProcesso($processo)) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }

        return new ItemEncaminhado(
            'Processo ' . $this->numeroCnj->numeroCnj($processo->getNumeroProcesso()),
            self::juntar([$processo->getClasseProcessual(), $processo->getSiglaTribunal()]),
        );
    }

    private function meta(?Tarefa $meta): ItemEncaminhado
    {
        if ($meta === null) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }

        $vence = $meta->getPrazo();
        $situacao = match (true) {
            $meta->getStatus() === Tarefa::STATUS_CONCLUIDA => 'concluída',
            $vence !== null => 'vence ' . $vence->format('d/m/Y'),
            default => '',
        };

        return new ItemEncaminhado(self::textoLimpo($meta->getTitulo()), $situacao);
    }

    private function prazo(?PastaPrazo $prazo): ItemEncaminhado
    {
        if ($prazo === null) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }

        return new ItemEncaminhado(self::textoLimpo($prazo->getTitulo()), self::juntar([
            $prazo->isAudiencia() ? 'Audiência' : 'Prazo',
            $prazo->getData()->format('d/m/Y'),
            (string) $prazo->getResponsavel(),
        ]));
    }

    private function push(Tenant $tenant, Pasta $pasta, int $id): ItemEncaminhado
    {
        $publicacao = $this->publicacoes->findOneByIdENumerosDoTenant($id, $tenant, $this->pastas->numerosDosProcessosVinculados($pasta, $tenant))
            ?? throw new ItemEncaminhadoNaoEncontradoException();

        $tipo = trim((string) $publicacao->getTipoComunicacao());
        $data = $publicacao->getDataDisponibilizacao();
        $titulo = ($tipo !== '' ? $tipo : 'Publicação') . ($data !== null ? ' de ' . $data->format('d/m/Y') : '');

        return new ItemEncaminhado(self::textoLimpo($titulo), (string) PublicacaoDjenListaItem::resumirTexto($publicacao->getTexto(), self::LIMITE_RESUMO));
    }

    private function notaTecnica(Tenant $tenant, Pasta $pasta, int $id): ItemEncaminhado
    {
        $nota = $this->notas->findOneByIdDoTenant($id, $tenant);
        if ($nota === null || !$pasta->temProcesso($nota->getProcesso())) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }

        return new ItemEncaminhado(
            'Nota técnica · ' . self::textoLimpo($nota->getConteudo(), self::LIMITE_TITULO_NOTA),
            self::textoLimpo($nota->getConteudo()),
        );
    }

    /** @param list<string> $partes as vazias saem */
    private static function juntar(array $partes): string
    {
        return implode(' · ', array_filter(array_map('trim', $partes), static fn (string $p): bool => $p !== ''));
    }

    /**
     * "Pasta NUP" (ou "Pasta #id" sem NUP), já desarmado: o título do tipo pasta e a linha que o despacho sempre leva
     * (`EncaminharItemDaPastaUseCase`), por isso os resumos por tipo não repetem a pasta.
     */
    public static function rotuloDaPasta(Pasta $pasta): string
    {
        $nup = trim((string) $pasta->getNup());

        return self::neutralizarMencoes('Pasta ' . ($nup !== '' ? $nup : '#' . $pasta->getId()));
    }
}

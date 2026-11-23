<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\CampanhaDoRelatorio;
use App\Comercial\DTO\EdicaoDaCampanha;
use App\Comercial\DTO\PedidoDeCampanha;
use App\Comercial\DTO\PedidoDeEdicao;
use App\Comercial\DTO\PedidoDePublico;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Service\ModelosEfetivos;
use App\Comercial\UseCase\AbrirCampanhaDoRelatorioUseCase;
use App\Comercial\UseCase\ConfirmarCampanhaUseCase;
use App\Comercial\UseCase\ConsultarEdicaoDaCampanhaUseCase;
use App\Comercial\UseCase\ContarPublicosUseCase;
use App\Comercial\UseCase\EditarCampanhaUseCase;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `campanhas_de_email` (`docs/specs/mcp-remoto-oauth.md`, 6.9, PR 20): cria e agenda, edita e reprograma, cancela e
 * testa as campanhas de e-mail do Comercial do Painel do Master. Só para o Master da plataforma: a guarda é a primeira
 * coisa do {@see executar}, do {@see reconferir} e do {@see executarConfirmada}.
 *
 * Tudo vira proposta (decisão 37, um "sim" por ação), porque tudo manda e-mail de verdade ou para o envio e não volta:
 * `criar` (só de modelo salvo, nunca a mensagem inteira: o resumo traz modelo, assunto, texto, público, total, data e
 * frequência), `editar` (agenda e, se pedido, o conteúdo regravado a partir do modelo salvo; só antes de a campanha
 * começar a sair), `cancelar` e `enviar_teste` (só ao e-mail do próprio Master). A proposta guarda o hash do modelo ou
 * do estado da campanha, e o {@see reconferir} recusa se mudou. Chave do envio desligada não recusa (o UseCase grava e
 * a campanha espera): o resumo avisa. Toda gravação roda dentro de {@see EscopoDeAuditoriaDePlataforma::executar()}.
 * Nenhuma resposta traz e-mail de destinatário; os públicos `csv` e `indiv` não existem aqui.
 */
// Não implementa o ExecutorDeProposta (as duas interfaces declaram `TAG`): o CampanhasDeEmailExecutor repassa.
final class CampanhasDeEmailFerramenta implements FerramentaDoConector
{
    public const NOME = 'campanhas_de_email';

    public const CRIAR = 'criar';
    public const EDITAR = 'editar';
    public const CANCELAR = 'cancelar';
    public const ENVIAR_TESTE = 'enviar_teste';
    public const ACOES = [self::CRIAR, self::EDITAR, self::CANCELAR, self::ENVIAR_TESTE];

    public const MOTIVO_SO_DO_MASTER = 'so_do_master';
    public const NAO_DISPONIVEL = 'Ferramenta não disponível.';
    public const MUDOU = 'A campanha ou o modelo mudou desde a proposta. Peça de novo.';
    public const ENVIO_DESLIGADO = 'Atenção: o envio de campanhas está desligado. A campanha fica esperando até o Master ligar o envio.';

    public function __construct(
        private readonly ModelosEfetivos $efetivos,
        private readonly ModelosDeEmailResumos $resumos,
        private readonly ContarPublicosUseCase $contar,
        private readonly ConfirmarCampanhaUseCase $confirmar,
        private readonly ConsultarEdicaoDaCampanhaUseCase $consultar,
        private readonly EditarCampanhaUseCase $editar,
        private readonly AbrirCampanhaDoRelatorioUseCase $abrir,
        private readonly EncerrarCampanhaUseCase $encerrar,
        private readonly EnviarTesteDaCampanhaUseCase $teste,
        private readonly ChaveDasCampanhas $chave,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $plataforma,
        private readonly PropostasDoConector $propostas,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Campanhas de e-mail do Painel do Master';
    }

    public function descricao(): string
    {
        return 'Cria, edita, cancela e testa as campanhas de e-mail do Comercial (só para o Master da plataforma). Toda ação espera o sim da pessoa: mostre o resumo e chame confirmar_acao só se ela disser sim. "criar" (modelo: id, cu12 ou chave de um modelo salvo, como veio em ler_comercial; publico: leads, prim, sec ou todos; frequencia: unica, semanal, quinzenal ou mensal; agendar: AAAA-MM-DDTHH:MM, hora de Brasília, ou vazio para enviar já) usa só o modelo salvo, nunca texto solto. "editar" (campanha_id; agendar, frequencia e atualizar_do_modelo, que regrava o conteúdo com a versão atual do modelo; só antes de a campanha começar a sair; o que não vier fica como está). "cancelar" (campanha_id) para o envio e não volta. "enviar_teste" (modelo) manda um teste só para o e-mail do próprio Master, no máximo 10 por hora. Lista de e-mails de destinatários nunca sai, e os públicos de CSV e individual não existem aqui.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => self::ACOES, 'description' => 'criar, editar, cancelar ou enviar_teste.'],
                'modelo' => ['type' => 'string', 'minLength' => 1, 'maxLength' => CampanhasDeEmailEntrada::TAMANHO_DO_MODELO, 'description' => 'Em criar e enviar_teste. O id (12), a chave de um próprio (cu12) ou a chave de um padrão.'],
                'publico' => ['type' => 'string', 'enum' => CampanhasDeEmailEntrada::PUBLICOS, 'description' => 'Só em criar. leads (base de contatos), prim, sec ou todos.'],
                'frequencia' => ['type' => 'string', 'enum' => CampanhaDeEmail::FREQUENCIAS, 'description' => 'Em criar e editar. unica (padrão), semanal, quinzenal ou mensal.'],
                'agendar' => ['type' => 'string', 'maxLength' => CampanhasDeEmailEntrada::TAMANHO_DA_DATA, 'description' => 'Em criar e editar. Data e hora do primeiro envio, AAAA-MM-DDTHH:MM, no futuro. Vazio: criar envia já; editar tira a data.'],
                'campanha_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em editar e cancelar. O id que veio na ação campanhas de ler_comercial.'],
                'atualizar_do_modelo' => ['type' => 'boolean', 'description' => 'Só em editar. true regrava o conteúdo da campanha com a versão atual do modelo dela.'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirMaster($chamada);
        $entrada = CampanhasDeEmailEntrada::deArgumentos($argumentos);

        return $this->recusando(fn (): RespostaDaFerramenta => match ($entrada->acao) {
            self::CRIAR => $this->proporCriacao($entrada->campos, $chamada),
            self::EDITAR => $this->proporEdicao($entrada->campos, $chamada),
            self::CANCELAR => $this->proporCancelamento((int) $entrada->campos['campanha_id'], $chamada),
            default => $this->proporTeste((string) $entrada->campos['modelo'], $chamada),
        });
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->exigirMaster($chamada);
        $this->recusando(function () use ($acao, $dados): void {
            match ($acao) {
                self::CRIAR, self::ENVIAR_TESTE => $this->exigirModeloIgual($dados),
                self::EDITAR => $this->reconferirEdicao($dados),
                self::CANCELAR => $this->exigirIgual(self::hash(self::estadoDaCampanha($this->relatorio(self::inteiro($dados, 'campanha_id')))), $dados),
                default => throw ErroDaFerramenta::entradaInvalida(),
            };
            if ($acao === self::CRIAR || ($acao === self::EDITAR && ($dados['agendar'] ?? '') !== '')) {
                $this->exigirDataFutura((string) ($dados['agendar'] ?? ''));
            }
        });
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $this->exigirMaster($chamada);
        $pessoa = $chamada->contexto->pessoa;
        $resposta = $this->recusando(fn (): array => $this->plataforma->executar(fn (): array => match ($acao) {
            self::CRIAR => $this->gravarCriacao($dados, $pessoa),
            self::EDITAR => $this->gravarEdicao($dados, $pessoa),
            self::CANCELAR => $this->gravarCancelamento(self::inteiro($dados, 'campanha_id'), $pessoa),
            self::ENVIAR_TESTE => $this->gravarTeste(self::texto($dados, 'modelo'), $pessoa),
            default => throw ErroDaFerramenta::entradaInvalida(),
        }));

        return new ResultadoDaConfirmacao($resposta, contagens: ['alteracao' => 1]);
    }

    /** @param array<string, mixed> $campos */
    private function proporCriacao(array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $modelo = $this->modelo((string) $campos['modelo']);
        $mensagem = ModelosDeEmailResumos::mensagem($modelo);
        $frequencia = (string) ($campos['frequencia'] ?? CampanhaDeEmail::UNICA);
        $agendar = (string) ($campos['agendar'] ?? '');
        $this->exigirDataFutura($agendar);
        $publico = (string) $campos['publico'];
        $total = $this->contar->executar(new PedidoDePublico($publico, modelo: $mensagem->chave))->destinatarios;
        if ($total === 0) {
            throw ErroDaFerramenta::recusada(ConfirmarCampanhaUseCase::SEM_DESTINATARIOS);
        }
        $dados = ['modelo' => $mensagem->chave, 'antes' => ModelosDeEmailResumos::hash($modelo), 'publico' => $publico, 'frequencia' => $frequencia, 'agendar' => $agendar, 'chave' => bin2hex(random_bytes(16))];
        $resumo = sprintf(
            "Criar campanha do modelo \"%s\" (%s).\nPúblico: %s, %d destinatário(s).\nEnvio: %s.\n%sAssunto: %s\nTexto:\n%s",
            (string) $modelo['nome'],
            $mensagem->chave,
            PublicosDaCampanha::ROTULOS[$publico],
            $total,
            self::fraseDaAgenda($frequencia, $agendar),
            $this->avisoDoEnvio(),
            $mensagem->assunto,
            $this->resumos->texto($mensagem),
        );

        return $this->propostas->propor($chamada, self::NOME, self::CRIAR, $dados, $resumo);
    }

    /** @param array<string, mixed> $campos */
    private function proporEdicao(array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $id = (int) $campos['campanha_id'];
        $edicao = $this->edicao($id);
        $frequencia = (string) ($campos['frequencia'] ?? $edicao->frequencia);
        $agendar = (string) ($campos['agendar'] ?? $edicao->agendar);
        $atualizar = (bool) ($campos['atualizar_do_modelo'] ?? false);
        if (!$atualizar && $frequencia === $edicao->frequencia && $agendar === $edicao->agendar) {
            return new RespostaDaFerramenta(['estado' => 'nada_a_fazer', 'campanha_id' => $id, 'mensagem' => 'Já está assim: nada foi alterado.']);
        }
        $this->exigirDataFutura($agendar);
        $dados = ['campanha_id' => $id, 'antes' => self::hash($edicao->paraArray()), 'frequencia' => $frequencia, 'agendar' => $agendar, 'atualizar_do_modelo' => $atualizar, 'modelo' => null, 'modelo_antes' => null];
        $resumo = sprintf(
            "Editar a campanha %d (\"%s\", %s).\nAgenda: de %s para %s.\n%s",
            $id,
            $edicao->nome,
            $edicao->estado,
            self::fraseDaAgenda($edicao->frequencia, $edicao->agendar),
            self::fraseDaAgenda($frequencia, $agendar),
            $this->avisoDoEnvio(),
        );
        if ($atualizar) {
            $modelo = $this->modelo((string) $edicao->modelo['chave']);
            $nova = ModelosDeEmailResumos::mensagem($modelo);
            $dados['modelo'] = $nova->chave;
            $dados['modelo_antes'] = ModelosDeEmailResumos::hash($modelo);
            $resumo .= sprintf("O conteúdo passa a ser o da versão atual do modelo \"%s\".\nAssunto novo: %s\nTexto novo:\n%s", (string) $modelo['nome'], $nova->assunto, $this->resumos->texto($nova));
        }

        return $this->propostas->propor($chamada, self::NOME, self::EDITAR, $dados, $resumo);
    }

    private function proporCancelamento(int $id, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $campanha = $this->relatorio($id);
        $resumo = sprintf(
            'Cancelar a campanha %d ("%s"). Situação: %s. Público: %s. Data: %s. O que ainda está na fila não sai, e não dá para desfazer.',
            $id,
            $campanha->assunto,
            $campanha->situacao,
            $campanha->publico,
            $campanha->quando,
        );

        return $this->propostas->propor($chamada, self::NOME, self::CANCELAR, ['campanha_id' => $id, 'antes' => self::hash(self::estadoDaCampanha($campanha))], $resumo);
    }

    private function proporTeste(string $chave, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $modelo = $this->modelo($chave);
        $mensagem = ModelosDeEmailResumos::mensagem($modelo);
        $resumo = sprintf(
            "Enviar um e-mail de teste do modelo \"%s\" (%s) só para o seu e-mail de Master (no máximo %d testes por hora).\nAssunto: %s\nTexto:\n%s",
            (string) $modelo['nome'],
            $chave,
            EnviarTesteDaCampanhaUseCase::TETO_POR_HORA,
            $mensagem->assunto,
            $this->resumos->texto($mensagem),
        );

        return $this->propostas->propor($chamada, self::NOME, self::ENVIAR_TESTE, ['modelo' => $chave, 'antes' => ModelosDeEmailResumos::hash($modelo)], $resumo);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function gravarCriacao(array $dados, User $pessoa): array
    {
        $mensagem = ModelosDeEmailResumos::mensagem($this->modelo(self::texto($dados, 'modelo')));
        $data = $this->data(self::texto($dados, 'agendar'));
        $pedido = new PedidoDeCampanha(self::texto($dados, 'chave'), $mensagem, new PedidoDePublico(self::texto($dados, 'publico'), modelo: $mensagem->chave), self::texto($dados, 'frequencia'), $data);
        $feita = $this->confirmar->executar($pedido, $pessoa);

        return ['estado' => 'concluida', 'acao' => self::CRIAR, 'campanha_id' => $feita->id, 'total' => $feita->total, 'mensagem' => $feita->mensagem];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function gravarEdicao(array $dados, User $pessoa): array
    {
        $id = self::inteiro($dados, 'campanha_id');
        $edicao = $this->edicao($id);
        $conteudo = $dados['modelo'] === null ? $edicao->modelo : $this->modelo(self::texto($dados, 'modelo'));
        $pedido = new PedidoDeEdicao($conteudo, self::texto($dados, 'frequencia'), $this->data(self::texto($dados, 'agendar')));
        $mensagem = $this->editar->executar($id, $pedido, $pessoa) ?? throw ErroDaFerramenta::naoEncontrado();

        return ['estado' => 'concluida', 'acao' => self::EDITAR, 'campanha_id' => $id, 'mensagem' => $mensagem];
    }

    /** @return array<string, mixed> */
    private function gravarCancelamento(int $id, User $pessoa): array
    {
        $mensagem = $this->encerrar->executar($id, EncerrarCampanhaUseCase::MOTIVO_DO_MASTER, $pessoa->getId(), $pessoa->getEmail());

        return ['estado' => 'concluida', 'acao' => self::CANCELAR, 'campanha_id' => $id, 'mensagem' => $mensagem];
    }

    /** @return array<string, mixed> */
    private function gravarTeste(string $chave, User $pessoa): array
    {
        $enviado = $this->teste->executar(ModelosDeEmailResumos::mensagem($this->modelo($chave)), $pessoa);

        return ['estado' => 'concluida', 'acao' => self::ENVIAR_TESTE, 'enviados' => \count($enviado->enviados), 'bloqueados' => \count($enviado->bloqueados), 'mensagem' => 'Teste enviado para o seu e-mail de Master.'];
    }

    /** @param array<string, mixed> $dados */
    private function reconferirEdicao(array $dados): void
    {
        $this->exigirIgual(self::hash($this->edicao(self::inteiro($dados, 'campanha_id'))->paraArray()), $dados);
        if (($dados['modelo'] ?? null) !== null) {
            $this->exigirModeloIgual($dados);
        }
    }

    /** @param array<string, mixed> $dados a chave do modelo está em `modelo` e o hash em `antes` (ou `modelo_antes`) */
    private function exigirModeloIgual(array $dados): void
    {
        $esperado = $dados['modelo_antes'] ?? $dados['antes'] ?? null;
        $atual = $this->efetivos->porChave(self::texto($dados, 'modelo'));
        if ($atual === null || !\is_string($esperado) || ModelosDeEmailResumos::hash($atual) !== $esperado) {
            throw ErroDaFerramenta::recusada(self::MUDOU);
        }
    }

    /** @param array<string, mixed> $dados */
    private function exigirIgual(string $hashAtual, array $dados): void
    {
        if ($hashAtual !== ($dados['antes'] ?? null)) {
            throw ErroDaFerramenta::recusada(self::MUDOU);
        }
    }

    /** @return array<string, mixed> */
    private function modelo(string $chave): array
    {
        return $this->efetivos->porChave($chave) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    private function edicao(int $id): EdicaoDaCampanha
    {
        return $this->consultar->executar($id) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    private function relatorio(int $id): CampanhaDoRelatorio
    {
        $campanha = $this->abrir->executar($id) ?? throw ErroDaFerramenta::naoEncontrado();
        if (!$campanha->cancelavel) {
            throw ErroDaFerramenta::recusada(EncerrarCampanhaUseCase::NAO_ATIVA);
        }

        return $campanha;
    }

    /** @return array<string, mixed> o que não muda só porque o envio avança (os contadores ficam de fora) */
    private static function estadoDaCampanha(CampanhaDoRelatorio $c): array
    {
        return ['id' => $c->id, 'estado' => $c->estado, 'assunto' => $c->assunto, 'publico' => $c->publico, 'cancelavel' => $c->cancelavel];
    }

    private function avisoDoEnvio(): string
    {
        return $this->chave->ligada() ? '' : self::ENVIO_DESLIGADO . "\n";
    }

    /** @throws ErroDaFerramenta */
    private function exigirDataFutura(string $agendar): void
    {
        $data = $this->data($agendar);
        if ($data !== null && $data <= $this->relogio->now()) {
            throw ErroDaFerramenta::recusada(ConfirmarCampanhaUseCase::DATA_PASSADA);
        }
    }

    /** @throws ErroDaFerramenta */
    private function data(string $agendar): ?\DateTimeImmutable
    {
        try {
            return PedidoDeCampanha::data($agendar);
        } catch (CampanhaRecusada $e) {
            throw ErroDaFerramenta::recusada($e->getMessage());
        }
    }

    private static function fraseDaAgenda(string $frequencia, string $agendar): string
    {
        $quando = $agendar === '' ? 'agora' : (\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $agendar) ?: null)?->format('d/m/Y H:i');
        $quando ??= $agendar;

        return $frequencia === CampanhaDeEmail::UNICA ? ($agendar === '' ? 'envio único, agora' : 'envio único em ' . $quando) : sprintf('%s, a partir de %s', $frequencia, $quando);
    }

    /** @param array<string, mixed> $conteudo */
    private static function hash(array $conteudo): string
    {
        return ModelosDeEmailResumos::hash($conteudo);
    }

    /**
     * A recusa do UseCase passa com a mensagem dele (constante do código, nunca texto do pedido).
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     *
     * @throws ErroDaFerramenta
     */
    private function recusando(\Closure $trabalho): mixed
    {
        try {
            return $trabalho();
        } catch (CampanhaRecusada $e) {
            throw ErroDaFerramenta::recusada($e->getMessage());
        }
    }

    /** @throws ErroDaFerramenta */
    private function exigirMaster(ChamadaDoConector $chamada): void
    {
        try {
            GuardaDoMasterDoConector::exigir($chamada->contexto->pessoa);
        } catch (AccessDeniedException) {
            throw new ErroDaFerramenta(self::MOTIVO_SO_DO_MASTER, self::NAO_DISPONIVEL);
        }
    }

    /** @param array<string, mixed> $dados */
    private static function texto(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? null;

        return \is_string($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /** @param array<string, mixed> $dados */
    private static function inteiro(array $dados, string $campo): int
    {
        $valor = $dados[$campo] ?? null;

        return \is_int($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }
}

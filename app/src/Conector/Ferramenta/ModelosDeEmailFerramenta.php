<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Service\ModelosEfetivos;
use App\Comercial\UseCase\CriarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\DuplicarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\ExcluirModeloDeCampanhaUseCase;
use App\Comercial\UseCase\SalvarComoMeuModeloUseCase;
use App\Comercial\UseCase\SalvarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\VoltarModeloAoPadraoUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `modelos_de_email` (`docs/specs/mcp-remoto-oauth.md`, 6.9, PR 19): cria, copia, edita e apaga os modelos de e-mail do
 * Comercial do Painel do Master, inclusive a versão do Master de um modelo padrão do sistema (M5.8). Só para o Master
 * da plataforma: a guarda é a primeira coisa do {@see executar}, do {@see reconferir} e do {@see executarConfirmada}.
 *
 * Ações: `criar`, `duplicar` e `salvar_como` não mandam nada a ninguém e gravam na hora. `editar`, `voltar_ao_padrao` e
 * `excluir` viram proposta (decisão 37), com assunto e texto em texto puro no resumo; o hash do modelo vai na proposta e
 * o {@see reconferir} recusa se ele mudou. Fora (decisão 41): importar HTML. A proposta guarda só os campos que a pessoa
 * mandou (o modelo vem inteiro do banco, conferido pelo hash); o resumo é cortado se o texto for grande. Toda gravação roda dentro de {@see EscopoDeAuditoriaDePlataforma::executar()}
 * (`audit_log` sem escritório, rota `conector_mcp`). Reusa os UseCases do Comercial; nada de SQL.
 */
// Não implementa o ExecutorDeProposta (as duas interfaces declaram `TAG`): o ModelosDeEmailExecutor repassa.
final class ModelosDeEmailFerramenta implements FerramentaDoConector
{
    public const NOME = 'modelos_de_email';

    public const CRIAR = 'criar';
    public const DUPLICAR = 'duplicar';
    public const SALVAR_COMO = 'salvar_como';
    public const EDITAR = 'editar';
    public const VOLTAR_AO_PADRAO = 'voltar_ao_padrao';
    public const EXCLUIR = 'excluir';
    public const ACOES = [self::CRIAR, self::DUPLICAR, self::SALVAR_COMO, self::EDITAR, self::VOLTAR_AO_PADRAO, self::EXCLUIR];

    public const MOTIVO_SO_DO_MASTER = 'so_do_master';
    public const NAO_DISPONIVEL = 'Ferramenta não disponível.';
    public const MUDOU = 'O modelo mudou desde a proposta. Peça de novo.';
    public const NOME_DE_PADRAO = 'O nome de um modelo padrão não muda.';
    public const CAMPO_DE_OUTRO_MODO = 'Este campo não vale para o modo deste modelo.';
    public const SO_PROPRIO = 'Só os modelos próprios (cu<id>) podem ser excluídos.';
    public const NADA_A_VOLTAR = 'Este modelo já está no padrão.';
    public const SO_PADRAO = 'Só os modelos padrão voltam ao padrão.';

    private const TRECHO_DO_QUE_SAI = 2_000;

    public function __construct(
        private readonly ModelosEfetivos $efetivos,
        private readonly ModelosDeCampanha $fixos,
        private readonly ModelosDeEmailResumos $resumos,
        private readonly CriarModeloDeCampanhaUseCase $criar,
        private readonly DuplicarModeloDeCampanhaUseCase $duplicar,
        private readonly SalvarComoMeuModeloUseCase $salvarComo,
        private readonly SalvarModeloDeCampanhaUseCase $salvar,
        private readonly VoltarModeloAoPadraoUseCase $voltar,
        private readonly ExcluirModeloDeCampanhaUseCase $excluir,
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
        return 'Modelos de e-mail do Painel do Master';
    }

    public function descricao(): string
    {
        return 'Cria, copia, edita e apaga os modelos de e-mail do Comercial (só para o Master da plataforma). O modelo é o id (12), a chave de um próprio (cu12) ou a chave de um padrão do sistema (convite, data-natal), como veio na ação modelos de ler_comercial. Ações: "criar" (um modelo próprio novo, em blocos), "duplicar" (modelo) e "salvar_como" (modelo e nome) gravam na hora, sem mandar nada a ninguém. "editar" (modelo e os campos a trocar: nome só em próprio, assunto, selo, titulo, corpo, itens, cta e link nos de modo simples, blocos nos de modo blocos, html nos de modo html; campo que não vier fica como está) vale também para os padrões do sistema, e grava a versão do Master. "voltar_ao_padrao" apaga a versão editada de um padrão. "excluir" apaga um modelo próprio. Editar, voltar ao padrão e excluir esperam o sim da pessoa: mostre o resumo (assunto e texto) e chame confirmar_acao só se ela disser sim. Importar HTML não está disponível. Campo acima do limite é recusado, não cortado.';
    }

    public function esquemaDeEntrada(): array
    {
        $texto = static fn (string $d, int $max): array => ['type' => 'string', 'maxLength' => $max, 'description' => $d];

        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => self::ACOES, 'description' => 'criar, duplicar, salvar_como, editar, voltar_ao_padrao ou excluir.'],
                'modelo' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40, 'description' => 'O id (12), a chave de um próprio (cu12) ou a chave de um padrão. Em todas as ações, menos criar.'],
                'nome' => $texto('Em salvar_como, o nome do novo modelo; em editar, o novo nome (só em modelo próprio).', 200),
                'assunto' => $texto('Só em editar. O assunto do e-mail.', 255),
                'selo' => $texto('Só em editar. O selo (texto pequeno acima do título).', 120),
                'titulo' => $texto('Só em editar. O título do e-mail.', 255),
                'corpo' => $texto('Só em editar, em modelo de modo simples. O texto.', 20000),
                'itens' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 500], 'description' => 'Só em editar, em modelo de modo simples. A lista de itens.'],
                'cta' => $texto('Só em editar, em modelo de modo simples. O texto do botão.', 120),
                'link' => $texto('Só em editar, em modelo de modo simples. O endereço do botão.', 2000),
                'html' => $texto('Só em editar, em modelo de modo html. O HTML inteiro do e-mail (é sanitizado ao gravar; script e estilo perigoso saem).', ModelosDeEmailEntrada::LIMITES['html']),
                'blocos' => ['type' => 'array', 'maxItems' => 60, 'items' => ['type' => 'object', 'properties' => ['t' => ['type' => 'string'], 'a' => ['type' => 'string', 'maxLength' => 20000], 'b' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['t'], 'additionalProperties' => false], 'description' => 'Só em editar, em modelo de modo blocos. A lista inteira de blocos (t: tipo, a: texto, b: complemento); substitui a atual.'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirMaster($chamada);
        $entrada = ModelosDeEmailEntrada::deArgumentos($argumentos);
        if ($entrada->acao === self::CRIAR) {
            return $this->gravarCopia($entrada, $chamada);
        }
        $atual = $this->efetivos->porChave((string) $entrada->modelo) ?? throw ErroDaFerramenta::naoEncontrado();

        return match ($entrada->acao) {
            self::DUPLICAR, self::SALVAR_COMO => $this->gravarCopia($entrada, $chamada, $atual),
            self::EDITAR => $this->proporEdicao($entrada, $atual, $chamada),
            self::VOLTAR_AO_PADRAO => $this->proporVoltar($entrada, $atual, $chamada),
            default => $this->proporExclusao($entrada, $atual, $chamada),
        };
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->exigirMaster($chamada);
        if (!\in_array($acao, [self::EDITAR, self::VOLTAR_AO_PADRAO, self::EXCLUIR], true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $chave = self::texto($dados, 'modelo');
        $atual = $this->efetivos->porChave($chave);
        if ($atual === null || ModelosDeEmailResumos::hash($atual) !== self::texto($dados, 'antes')) {
            throw ErroDaFerramenta::recusada(self::MUDOU);
        }
        if ($acao === self::EDITAR) {
            ModelosDeEmailResumos::mensagem(self::comEdicao($atual, self::camposDosDados($dados)));
        } else {
            $this->exigirAcaoPossivel($acao, $chave);
        }
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $this->exigirMaster($chamada);
        $chave = self::texto($dados, 'modelo');
        $pessoa = $chamada->contexto->pessoa;
        $resposta = $this->recusando(fn (): array => $this->plataforma->executar(fn (): array => match ($acao) {
            self::EDITAR => $this->gravarEdicao($chave, self::camposDosDados($dados), $pessoa),
            self::VOLTAR_AO_PADRAO => ['estado' => 'concluida', 'acao' => $acao, 'modelo' => self::resumoDoModelo($this->voltar->executar($chave, $pessoa))],
            self::EXCLUIR => $this->excluirModelo($chave, $pessoa),
            default => throw ErroDaFerramenta::entradaInvalida(),
        }));

        return new ResultadoDaConfirmacao($resposta, contagens: ['alteracao' => 1]);
    }

    /** @param array<string, mixed>|null $atual */
    private function gravarCopia(ModelosDeEmailEntrada $entrada, ChamadaDoConector $chamada, ?array $atual = null): RespostaDaFerramenta
    {
        $pessoa = $chamada->contexto->pessoa;
        $base = $atual === null ? null : ModelosDeEmailResumos::mensagem($atual);
        $saida = $this->recusando(fn (): ModeloSalvoOutput => $this->plataforma->executar(fn (): ModeloSalvoOutput => match ($entrada->acao) {
            self::CRIAR => $this->criar->executar($pessoa),
            self::DUPLICAR => $this->duplicar->executar($base ?? throw ErroDaFerramenta::entradaInvalida(), $pessoa),
            default => $this->salvarComo->executar($base ?? throw ErroDaFerramenta::entradaInvalida(), (string) $entrada->campos['nome'], $pessoa),
        }));

        return new RespostaDaFerramenta(self::saidaDoModelo($entrada->acao, $saida), contagens: ['alteracao' => 1]);
    }

    /** @param array<string, mixed> $atual */
    private function proporEdicao(ModelosDeEmailEntrada $entrada, array $atual, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $depois = ModelosDeEmailResumos::mensagem(self::comEdicao($atual, $entrada->campos));
        if ($depois->paraArray() === ModelosDeEmailResumos::mensagem($atual)->paraArray()) {
            return new RespostaDaFerramenta(['estado' => 'nada_a_fazer', 'modelo' => $entrada->modelo, 'mensagem' => 'Já está assim: nada foi alterado.']);
        }
        $dados = ['modelo' => $entrada->modelo, 'antes' => ModelosDeEmailResumos::hash($atual), 'campos' => $entrada->campos];
        $resumo = sprintf(
            "Editar o modelo \"%s\" (%s). %s\nAssunto novo: %s\nTexto novo:\n%s",
            (string) $atual['nome'],
            (string) $entrada->modelo,
            ($atual['proprio'] ?? false) ? 'Quem usa este modelo passa a mandar o texto novo.' : 'Grava a versão do Master deste modelo padrão: as automações e datas que o usam passam a mandar o texto novo.',
            $depois->assunto,
            $this->resumos->texto($depois),
        );

        return $this->propostas->propor($chamada, self::NOME, self::EDITAR, $dados, $resumo);
    }

    /**
     * Grava a edição: o modelo atual (já conferido pelo hash no reconferir) com os campos da proposta por cima.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function gravarEdicao(string $chave, array $campos, User $pessoa): array
    {
        $atual = $this->efetivos->porChave($chave) ?? throw ErroDaFerramenta::recusada(self::MUDOU);
        $mensagem = ModelosDeEmailResumos::mensagem(self::comEdicao($atual, $campos));

        return self::saidaDoModelo(self::EDITAR, $this->salvar->executar(self::idParaOUseCase($chave), $mensagem, $pessoa));
    }

    /** @param array<string, mixed> $atual */
    private function proporVoltar(ModelosDeEmailEntrada $entrada, array $atual, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $chave = (string) $entrada->modelo;
        $this->exigirAcaoPossivel(self::VOLTAR_AO_PADRAO, $chave);
        $padrao = ModelosDeEmailResumos::mensagem(ModeloDaGrade::deFixo((array) $this->fixos->fixo($chave)));
        $resumo = sprintf(
            "Voltar o modelo padrão \"%s\" (%s) ao texto do sistema. A versão editada é apagada.\nAssunto editado que se perde: %s\nTexto editado que se perde:\n%s\nAssunto que volta: %s\nTexto que volta:\n%s",
            (string) $atual['nome'],
            $chave,
            (string) $atual['assunto'],
            $this->resumos->texto(ModelosDeEmailResumos::mensagem($atual), self::TRECHO_DO_QUE_SAI, ModelosDeEmailResumos::CORTADO),
            $padrao->assunto,
            $this->resumos->texto($padrao, self::TRECHO_DO_QUE_SAI, ModelosDeEmailResumos::CORTADO),
        );

        return $this->propostas->propor($chamada, self::NOME, self::VOLTAR_AO_PADRAO, ['modelo' => $chave, 'antes' => ModelosDeEmailResumos::hash($atual)], $resumo);
    }

    /** @param array<string, mixed> $atual */
    private function proporExclusao(ModelosDeEmailEntrada $entrada, array $atual, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $chave = (string) $entrada->modelo;
        $this->exigirAcaoPossivel(self::EXCLUIR, $chave);
        $mensagem = ModelosDeEmailResumos::mensagem($atual);
        $resumo = sprintf(
            "Excluir o modelo \"%s\" (%s). Campanhas já criadas não mudam.\nAssunto: %s\nTexto:\n%s",
            (string) $atual['nome'],
            $chave,
            $mensagem->assunto,
            $this->resumos->texto($mensagem, self::TRECHO_DO_QUE_SAI, ModelosDeEmailResumos::CORTADO),
        );

        return $this->propostas->propor($chamada, self::NOME, self::EXCLUIR, ['modelo' => $chave, 'antes' => ModelosDeEmailResumos::hash($atual)], $resumo);
    }

    /** @return array<string, mixed> */
    private function excluirModelo(string $chave, User $pessoa): array
    {
        $this->excluir->executar(self::idParaOUseCase($chave), $pessoa);

        return ['estado' => 'concluida', 'acao' => self::EXCLUIR, 'modelo' => ['chave' => $chave]];
    }

    /** @throws ErroDaFerramenta */
    private function exigirAcaoPossivel(string $acao, string $chave): void
    {
        if ($acao === self::EXCLUIR && !str_starts_with($chave, 'cu')) {
            throw ErroDaFerramenta::recusada(self::SO_PROPRIO);
        }
        if ($acao !== self::VOLTAR_AO_PADRAO) {
            return;
        }
        if (!$this->fixos->ehFixo($chave)) {
            throw ErroDaFerramenta::recusada(self::SO_PADRAO);
        }
        if (!($this->efetivos->porChave($chave)['editado'] ?? false)) {
            throw ErroDaFerramenta::recusada(self::NADA_A_VOLTAR);
        }
    }

    /**
     * O modelo atual com os campos pedidos por cima. Campo que não vale para o modo ou para o tipo do modelo é recusado.
     *
     * @param array<string, mixed> $atual
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private static function comEdicao(array $atual, array $campos): array
    {
        $modo = (string) $atual['modo'];
        $validos = match ($modo) {
            'simples' => ['corpo', 'itens', 'cta', 'link'],
            'blocos' => ['blocos'],
            'html' => ['html'],
            default => [],
        };
        foreach (array_keys($campos) as $campo) {
            if (\in_array($campo, ['assunto', 'selo', 'titulo'], true)) {
                continue;
            }
            if ($campo === 'nome' && !($atual['proprio'] ?? false)) {
                throw ErroDaFerramenta::recusada(self::NOME_DE_PADRAO);
            }
            if ($campo !== 'nome' && !\in_array($campo, $validos, true)) {
                throw ErroDaFerramenta::recusada(self::CAMPO_DE_OUTRO_MODO);
            }
        }

        return $campos + $atual;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private static function camposDosDados(array $dados): array
    {
        $campos = $dados['campos'] ?? null;

        return \is_array($campos) ? $campos : throw ErroDaFerramenta::entradaInvalida();
    }

    /** `cu12` vira `12`; a chave de um padrão fica. */
    private static function idParaOUseCase(string $chave): string
    {
        return preg_match('/^cu(\d+)$/', $chave, $m) === 1 ? $m[1] : $chave;
    }

    /** @return array<string, mixed> */
    private static function saidaDoModelo(string $acao, ModeloSalvoOutput $saida): array
    {
        return ['estado' => 'concluida', 'acao' => $acao, 'modelo' => self::resumoDoModelo($saida->modelo)];
    }

    /**
     * @param array<string, mixed> $modelo
     *
     * @return array<string, mixed>
     */
    private static function resumoDoModelo(array $modelo): array
    {
        return [
            'chave' => (string) $modelo['chave'],
            'id' => ($modelo['id'] ?? null) === null ? null : (int) $modelo['id'],
            'nome' => (string) ($modelo['nome'] ?? ''),
            'modo' => (string) ($modelo['modo'] ?? ''),
            'assunto' => (string) ($modelo['assunto'] ?? ''),
            'proprio' => (bool) ($modelo['proprio'] ?? false),
            'editado' => (bool) ($modelo['editado'] ?? false),
        ];
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
}

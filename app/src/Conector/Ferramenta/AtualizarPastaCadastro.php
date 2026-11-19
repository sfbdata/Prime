<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Pasta\DTO\CadastroDaPastaInput;
use App\Pasta\DTO\CadastroDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Repository\PastaReuRepository;
use App\Pasta\Service\DadosDaCobrancaNaPasta;
use App\Pasta\UseCase\GerenciarReusDaPastaUseCase;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Pasta\UseCase\SalvarCadastroDaPastaUseCase;
use App\Shared\Service\DigitoVerificador;

/**
 * O cadastro da pasta pela `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6), sempre com o recurso `pasta` de
 * editar, como as rotas da tela:
 *
 *  - `nome_do_cliente` (`nome`): o identificador da pasta ({@see RenomearClienteDaPastaUseCase}, F2 da Lista). Dar nome a
 *    pasta sem nome é direto; trocar o nome que já existe é gravar por cima e vai por proposta. A pasta judicializada
 *    pela cobrança (nome montado pela carteira) recusa, como a tela;
 *  - `cadastro_da_pasta` (os campos do "Cadastro da pasta" da Controladoria, {@see SalvarCadastroDaPastaUseCase}): só os
 *    campos mandados mudam; preencher o que está vazio é direto, trocar ou apagar o que está preenchido vai por proposta.
 *    O resumo diz quais campos mudam e o valor novo mandado pelo assistente, passado pela proteção (que mascara CPF e
 *    e-mail, 6.2.4); o valor antigo nunca aparece, nem no resumo nem na proposta. A resposta da gravação direta diz só
 *    QUAIS campos mudaram;
 *  - `reu` (`operacao` adicionar com `nome` e `documento?`, ou subir com `reu_id`): acrescentar e mudar a ordem são
 *    diretos ({@see GerenciarReusDaPastaUseCase}); a resposta nunca traz o CPF ou CNPJ.
 *
 * A confirmação recusa se o nome ou o cadastro mudaram desde a proposta (o cadastro é comparado por um resumo de hash,
 * para o valor antigo nunca ficar gravado na proposta).
 */
final class AtualizarPastaCadastro implements AtualizarPastaGrupo
{
    public const NOME_DA_CARTEIRA = 'O nome desta pasta é montado a partir do credor da carteira e não se edita pelo conector nem pela tela.';
    public const NOME_VAZIO = 'O nome do cliente não pode ficar vazio.';
    public const RECUSA_NOME = 'O BlueJus recusou o nome do cliente. Confira o nome (até 255 caracteres) e tente de novo.';
    public const RECUSA_CADASTRO = 'O BlueJus recusou o cadastro da pasta. Confira o nome do réu, o CPF ou CNPJ, o e-mail e a data de prescrição (AAAA-MM-DD) e tente de novo.';
    public const DOCUMENTO_INVALIDO = 'CPF ou CNPJ inválido: use 11 ou 14 dígitos válidos.';
    public const EMAIL_INVALIDO = 'E-mail inválido. Confira o endereço.';
    public const PRESCRICAO_INVALIDA = 'Data-limite de prescrição inválida: use o formato AAAA-MM-DD.';
    public const REU_SEM_NOME = 'Informe o nome do réu para salvar o CPF, a qualificação, o e-mail ou o endereço.';
    public const TEXTO_INVALIDO = 'Um dos campos passou do tamanho máximo ou tem caracteres inválidos (< > ou de controle).';
    public const RECUSA_REU = 'O BlueJus recusou o réu. Confira o nome, o CPF ou CNPJ (11 ou 14 dígitos válidos), se ele já não está na lista e o limite de réus da pasta.';

    /** O campo da ferramenta e o rótulo da tela, na ordem do formulário. */
    private const ROTULOS = [
        'nome_do_reu' => 'Nome do réu',
        'cpf' => 'CPF ou CNPJ do réu',
        'qualificacao' => 'Qualificação',
        'email' => 'E-mail do réu',
        'endereco' => 'Endereço do réu',
        'contrato' => 'Contrato',
        'unidade' => 'Unidade',
        'titular_extrato' => 'Titular do extrato',
        'prescricao' => 'Data-limite de prescrição',
    ];

    /** O tamanho máximo de cada campo no {@see SalvarCadastroDaPastaUseCase}. */
    private const MAXIMOS = ['nome_do_reu' => 255, 'qualificacao' => 500, 'email' => 255, 'endereco' => 500, 'contrato' => 120, 'unidade' => 120, 'titular_extrato' => 255];

    private const CAMPOS_DO_REU = ['adicionar' => ['nome', 'documento'], 'subir' => ['reu_id']];

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AtualizarPastaTexto $texto,
        private readonly PropostasDoConector $propostas,
        private readonly RenomearClienteDaPastaUseCase $renomear,
        private readonly SalvarCadastroDaPastaUseCase $salvarCadastro,
        private readonly GerenciarReusDaPastaUseCase $reus,
        private readonly PastaReuRepository $reusDaPasta,
        private readonly DadosDaCobrancaNaPasta $cobranca,
    ) {
    }

    public function acoes(): array
    {
        return ['nome_do_cliente', 'cadastro_da_pasta', 'reu'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pasta = $this->acesso->viva($chamada, $entrada->acao, $entrada->pastaId(), true);

        return match ($entrada->acao) {
            'nome_do_cliente' => $this->nomeDoCliente($pasta, $entrada->textoObrigatorio('nome'), $chamada),
            'cadastro_da_pasta' => $this->cadastro($pasta, $entrada, $chamada),
            default => $this->reu($pasta, $entrada, $chamada),
        };
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->conferida($acao, $dados, $chamada);
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $pasta = $this->conferida($acao, $dados, $chamada);
        $id = (int) $pasta->getId();
        $resposta = $acao === 'nome_do_cliente'
            ? $this->gravarNome($pasta, (string) AtualizarPastaRecusa::texto($dados, 'depois'), $chamada)
            : $this->gravarCadastro($pasta, self::camposDosDados($dados), self::listaDeNomes($dados), $chamada);

        return new ResultadoDaConfirmacao($resposta, [$id], ['pasta' => 1], ['pasta' => [$id]]);
    }

    /**
     * As conferências de acesso de novo e a pasta igual à da proposta.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private function conferida(string $acao, array $dados, ChamadaDoConector $chamada): Pasta
    {
        if ($acao !== 'nome_do_cliente' && $acao !== 'cadastro_da_pasta') {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pasta = $this->acesso->viva($chamada, $acao, AtualizarPastaRecusa::inteiro($dados, 'pasta_id'), true);
        if ($acao === 'nome_do_cliente') {
            $this->exigirNomeEditavel($pasta);
            $atual = (string) $pasta->getNomeCliente();
        } else {
            $atual = self::assinatura($this->atuais($pasta, $chamada));
        }
        if ($atual !== AtualizarPastaRecusa::texto($dados, 'antes')) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
        }

        return $pasta;
    }

    // ------------------------------------------------------------------ nome do cliente

    /** @throws ErroDaFerramenta */
    private function nomeDoCliente(Pasta $pasta, string $nome, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirNomeEditavel($pasta);
        $novo = mb_strtoupper(AtualizarPastaTexto::umaLinha($nome));
        if ($novo === '') {
            throw ErroDaFerramenta::recusada(self::NOME_VAZIO);
        }
        $atual = (string) $pasta->getNomeCliente();
        if ($novo === $atual) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $id = (int) $pasta->getId();
        if ($atual === '') {
            return new RespostaDaFerramenta($this->gravarNome($pasta, $novo, $chamada), ids: ['pasta' => [$id]]);
        }
        $escritorio = $chamada->contexto->escritorio;
        $resumo = sprintf(
            'Trocar o nome do cliente da pasta %s de %s para %s.',
            $this->texto->numero($pasta, $escritorio),
            AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $atual)),
            AtualizarPastaTexto::citado($novo),
        );

        return $this->propostas->propor($chamada, AtualizarPastaFerramenta::NOME, 'nome_do_cliente', ['pasta_id' => $id, 'antes' => $atual, 'depois' => $novo], $resumo);
    }

    /** @throws ErroDaFerramenta */
    private function exigirNomeEditavel(Pasta $pasta): void
    {
        if ($this->renomear->nomeEMontadoPelaCarteira($pasta)) {
            throw ErroDaFerramenta::recusada(self::NOME_DA_CARTEIRA);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function gravarNome(Pasta $pasta, string $nome, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $gravado = AtualizarPastaRecusa::gravando(fn (): string => $this->renomear->executar($pasta, $nome, $contexto->pessoa, $contexto->escritorio), self::RECUSA_NOME);

        return [
            'estado' => 'concluida',
            'acao' => 'nome_do_cliente',
            'pasta_id' => (int) $pasta->getId(),
            'nome_cliente' => $this->texto->protegido($contexto->escritorio, $gravado),
        ];
    }

    // ------------------------------------------------------------------ cadastro da pasta

    /** @throws ErroDaFerramenta */
    private function cadastro(Pasta $pasta, AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $atuais = $this->atuais($pasta, $chamada);
        $novos = $atuais;
        foreach (array_keys(self::ROTULOS) as $campo) {
            if ($entrada->tem($campo)) {
                $novos[$campo] = self::normalizado($campo, $entrada->textoObrigatorio($campo));
            }
        }
        $mudam = array_keys(array_diff_assoc($novos, $atuais));
        if ($mudam === []) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        self::validar($novos);
        $porCima = array_values(array_filter($mudam, static fn (string $campo): bool => $atuais[$campo] !== ''));
        if ($porCima === []) {
            return new RespostaDaFerramenta($this->gravarCadastro($pasta, $novos, $mudam, $chamada), ids: ['pasta' => [(int) $pasta->getId()]]);
        }
        $dados = ['pasta_id' => (int) $pasta->getId(), 'antes' => self::assinatura($atuais), 'campos' => $novos, 'mudam' => $mudam];

        return $this->propostas->propor($chamada, AtualizarPastaFerramenta::NOME, 'cadastro_da_pasta', $dados, $this->resumoDoCadastro($pasta, $atuais, $novos, $mudam, $chamada));
    }

    /**
     * Os campos do cadastro como a tela mostra (com a unidade e o titular sugeridos pela Cobrança), já normalizados.
     *
     * @return array<string, string>
     */
    private function atuais(Pasta $pasta, ChamadaDoConector $chamada): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $tela = CadastroDaPastaOutput::de($pasta, $this->reusDaPasta->daPasta($pasta, $escritorio)[0] ?? null, $this->cobranca->da($pasta, $escritorio));
        $valores = [$tela->reu, $tela->cpf, $tela->qualificacao, $tela->email, $tela->endereco, $tela->contrato, $tela->unidade, $tela->titularExtrato, $tela->prescricao];
        $atuais = [];
        foreach (array_keys(self::ROTULOS) as $i => $campo) {
            $atuais[$campo] = self::normalizado($campo, $valores[$i]);
        }

        return $atuais;
    }

    /**
     * @param array<string, string> $atuais
     * @param array<string, string> $novos
     * @param list<string>          $mudam
     */
    private function resumoDoCadastro(Pasta $pasta, array $atuais, array $novos, array $mudam, ChamadaDoConector $chamada): string
    {
        $escritorio = $chamada->contexto->escritorio;
        $linhas = [sprintf('Atualizar o cadastro da pasta %s (Controladoria):', $this->texto->numero($pasta, $escritorio))];
        foreach ($mudam as $campo) {
            $novo = AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $novos[$campo]));
            $linhas[] = self::ROTULOS[$campo] . ': ' . match (true) {
                $novos[$campo] === '' => 'apagar.',
                $atuais[$campo] === '' => 'preencher com ' . $novo . '.',
                default => 'trocar para ' . $novo . '.',
            };
        }

        return implode("\n", $linhas);
    }

    /**
     * @param array<string, string> $campos
     * @param list<string>          $mudam
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function gravarCadastro(Pasta $pasta, array $campos, array $mudam, ChamadaDoConector $chamada): array
    {
        self::validar($campos);
        $contexto = $chamada->contexto;
        $input = new CadastroDaPastaInput(
            $campos['nome_do_reu'],
            $campos['cpf'],
            $campos['qualificacao'],
            $campos['email'],
            $campos['endereco'],
            $campos['contrato'],
            $campos['unidade'],
            $campos['titular_extrato'],
            $campos['prescricao'],
        );
        $completo = AtualizarPastaRecusa::gravando(fn (): bool => $this->salvarCadastro->executar($pasta, $contexto->pessoa, $contexto->escritorio, $input), self::RECUSA_CADASTRO);

        return [
            'estado' => 'concluida',
            'acao' => 'cadastro_da_pasta',
            'pasta_id' => (int) $pasta->getId(),
            'campos_alterados' => array_map(static fn (string $campo): string => self::ROTULOS[$campo], $mudam),
            'cadastro_do_reu_completo' => $completo,
        ];
    }

    /**
     * O que o {@see SalvarCadastroDaPastaUseCase} recusaria, conferido antes de propor (a proposta nunca nasce para um
     * cadastro que a confirmação não gravaria).
     *
     * @param array<string, string> $campos
     *
     * @throws ErroDaFerramenta
     */
    private static function validar(array $campos): void
    {
        foreach (self::MAXIMOS as $campo => $maximo) {
            if (mb_strlen($campos[$campo]) > $maximo || preg_match('/[\p{Cc}<>]/u', $campos[$campo]) === 1) {
                throw ErroDaFerramenta::recusada(self::TEXTO_INVALIDO);
            }
        }
        $cpf = $campos['cpf'];
        if ($cpf !== '' && !(\strlen($cpf) === 11 ? DigitoVerificador::cpfValido($cpf) : (\strlen($cpf) === 14 && DigitoVerificador::cnpjValido($cpf)))) {
            throw ErroDaFerramenta::recusada(self::DOCUMENTO_INVALIDO);
        }
        if ($campos['email'] !== '' && filter_var($campos['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ErroDaFerramenta::recusada(self::EMAIL_INVALIDO);
        }
        self::validarPrescricao($campos['prescricao']);
        if ($campos['nome_do_reu'] === '' && ($cpf !== '' || $campos['qualificacao'] !== '' || $campos['email'] !== '' || $campos['endereco'] !== '')) {
            throw ErroDaFerramenta::recusada(self::REU_SEM_NOME);
        }
    }

    /** @throws ErroDaFerramenta */
    private static function validarPrescricao(string $valor): void
    {
        if ($valor === '') {
            return;
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw ErroDaFerramenta::recusada(self::PRESCRICAO_INVALIDA);
        }
    }

    private static function normalizado(string $campo, string $valor): string
    {
        return match ($campo) {
            'cpf' => DigitoVerificador::digitos($valor),
            'nome_do_reu' => PastaReu::normalizarNome($valor),
            default => trim((string) preg_replace('/\s+/u', ' ', $valor)),
        };
    }

    /** @param array<string, string> $campos */
    private static function assinatura(array $campos): string
    {
        return hash('sha256', (string) json_encode($campos, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Os campos do cadastro guardados na proposta, todos presentes e em texto.
     *
     * @param array<string, mixed> $dados
     *
     * @return array<string, string>
     *
     * @throws ErroDaFerramenta
     */
    private static function camposDosDados(array $dados): array
    {
        $guardados = $dados['campos'] ?? null;
        $campos = [];
        foreach (array_keys(self::ROTULOS) as $campo) {
            $valor = \is_array($guardados) ? ($guardados[$campo] ?? null) : null;
            $campos[$campo] = \is_string($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
        }

        return $campos;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return list<string>
     *
     * @throws ErroDaFerramenta
     */
    private static function listaDeNomes(array $dados): array
    {
        $mudam = $dados['mudam'] ?? null;
        if (!\is_array($mudam) || array_diff($mudam, array_keys(self::ROTULOS)) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return array_values(array_map('strval', $mudam));
    }

    // ------------------------------------------------------------------ réu

    /** @throws ErroDaFerramenta */
    private function reu(Pasta $pasta, AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $operacao = $entrada->textoObrigatorio('operacao');
        foreach (['nome', 'documento', 'reu_id'] as $campo) {
            if ($entrada->tem($campo) && !\in_array($campo, self::CAMPOS_DO_REU[$operacao], true)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        $contexto = $chamada->contexto;
        $lista = $operacao === 'adicionar'
            ? AtualizarPastaRecusa::gravando(fn (): array => $this->reus->adicionar($pasta, $contexto->pessoa, $contexto->escritorio, $entrada->textoObrigatorio('nome'), (string) $entrada->texto('documento')), self::RECUSA_REU)
            : $this->subir($pasta, $entrada->inteiroObrigatorio('reu_id'), $chamada);
        $reus = array_map(fn (PastaReu $r): array => [
            'id' => (int) $r->getId(),
            'nome' => $this->texto->protegido($contexto->escritorio, $r->getNome()),
            'tem_documento' => $r->getDocumento() !== null,
        ], $lista);
        $id = (int) $pasta->getId();

        return new RespostaDaFerramenta(['estado' => 'concluida', 'acao' => 'reu', 'operacao' => $operacao, 'pasta_id' => $id, 'reus' => $reus, 'principal' => 'O primeiro réu da lista é o principal.'], ids: ['pasta' => [$id]]);
    }

    /**
     * @return list<PastaReu>
     *
     * @throws ErroDaFerramenta
     */
    private function subir(Pasta $pasta, int $reuId, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $atuais = $this->reusDaPasta->daPasta($pasta, $contexto->escritorio);
        $ids = array_map(static fn (PastaReu $r): int => (int) $r->getId(), $atuais);
        $posicao = array_search($reuId, $ids, true);
        if ($posicao === false) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        if ($posicao === 0) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }

        return AtualizarPastaRecusa::gravando(fn (): array => $this->reus->subir($pasta, $contexto->pessoa, $contexto->escritorio, $reuId), self::RECUSA_REU);
    }
}

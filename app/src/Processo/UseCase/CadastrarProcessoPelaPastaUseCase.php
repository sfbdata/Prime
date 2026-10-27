<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\DTO\ProcessoDoCadastro;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\ConsultasDoCnjGuardadas;
use App\Processo\Service\DatajudProcessoMapper;
use App\Processo\Service\TribunalCnjResolver;

/**
 * Cadastro de processo novo pela janela "Vincular processo" da pasta (desenho 02, `procSalvarVinc`).
 *
 * Quem: usuário com EDIT na pasta (o controller confere). O quê: criar o processo que vai ser vinculado,
 * com os dados que a janela mostrou. Regras do desenho: número completo no padrão CNJ e órgão julgador,
 * classe e assunto obrigatórios, na mesma lista do "Preencha: ...". Situação e instância só nas opções da janela (vazio vira
 * "Em andamento" e "G1"); datas válidas; os limites das colunas viram mensagem, não erro de banco.
 * Linha de parte sem nome e de movimentação sem descrição é ignorada, como no desenho.
 *
 * Número que já existe no escritório (procurado antes da validação, para achar também o número antigo fora do
 * padrão CNJ): devolve o existente (`jaExistia`), sem aplicar nada do que foi digitado.
 *
 * Com a consulta do CNJ guardada no servidor ({@see ConsultasDoCnjGuardadas}, nunca vinda do navegador), o
 * `DatajudProcessoMapper` entra primeiro e traz o que a janela não carrega (assuntos, sigilo, formato, sistema,
 * códigos, id e o bruto do Datajud); depois a janela vence, campo a campo: o que a pessoa editou é o que vale.
 * Partes e movimentações são as da janela (a pessoa pode ter tirado linhas); a movimentação que veio igual do
 * CNJ guarda os códigos e os complementos do Datajud.
 *
 * Persiste sem flush: o vínculo grava na mesma transação, e a recusa de qualquer passo não deixa processo solto.
 *
 * @throws DadosDoProcessoInvalidosException o controller responde 422 com a mensagem
 */
final class CadastrarProcessoPelaPastaUseCase
{
    public const SITUACOES = ['EM_ANDAMENTO', 'SUSPENSO', 'ARQUIVADO', 'BAIXADO', 'TRANSITADO_EM_JULGADO'];
    public const INSTANCIAS = ['G1', 'G2', 'JE', 'TR', 'ST'];
    public const TIPOS_DE_PARTE = ['PARTE', 'ADVOGADO', 'TERCEIRO', 'MINISTERIO_PUBLICO', 'PERITO'];

    public function __construct(
        private readonly ProcessoRepository $processoRepository,
        private readonly TribunalCnjResolver $tribunalResolver,
        private readonly DatajudProcessoMapper $mapper,
    ) {
    }

    /** @param array<mixed>|null $consultaDoCnj o `_source` guardado no servidor para este número, se houver */
    public function executar(CadastroDoProcessoInput $input, Tenant $tenant, User $usuario, ?array $consultaDoCnj = null): ProcessoDoCadastro
    {
        // Antes da validação: um processo antigo do escritório com número fora do padrão CNJ continua sendo achado.
        $numero = $input->numeroSoDigitos();
        $existente = $numero !== '' ? $this->processoRepository->findByNumeroProcessoDoTenant($numero, $tenant) : null;
        if ($existente !== null) {
            return new ProcessoDoCadastro($existente, true);
        }

        $this->exigirObrigatorios($input, $numero);
        $processo = (new Processo())->setTenant($tenant);
        $movimentacoesDoCnj = $consultaDoCnj !== null ? $this->aplicarConsultaDoCnj($processo, $consultaDoCnj) : [];
        $this->aplicarDadosDaJanela($processo, $input, $numero, $tenant, $movimentacoesDoCnj);
        $processo->setCriadoPor($usuario);
        $this->processoRepository->save($processo);

        return new ProcessoDoCadastro($processo, false);
    }

    /** Como o desenho (`procSalvarVinc`): o número fora do padrão CNJ entra na mesma lista do "Preencha:". */
    private function exigirObrigatorios(CadastroDoProcessoInput $input, string $numero): void
    {
        $faltam = array_keys(array_filter([
            'número do processo' => strlen($numero) === 20 ? 'ok' : '',
            'órgão julgador' => $input->orgaoJulgador,
            'classe processual' => $input->classeProcessual,
            'assunto processual' => $input->assuntoProcessual,
        ], static fn (string $valor): bool => $valor === ''));

        if ($faltam !== []) {
            throw new DadosDoProcessoInvalidosException('Preencha: ' . implode(', ', $faltam) . '.');
        }
    }

    /**
     * O mapper preenche tudo; partes e movimentações saem do processo (a janela decide quais ficam), e as
     * movimentações do CNJ voltam indexadas para a janela reaproveitar a que veio igual.
     *
     * @param array<mixed> $consultaDoCnj
     *
     * @return array<string, MovimentacaoProcesso>
     */
    private function aplicarConsultaDoCnj(Processo $processo, array $consultaDoCnj): array
    {
        $this->mapper->mapFromSource($processo, $consultaDoCnj);
        foreach ($processo->getPartes()->toArray() as $parte) {
            $processo->removeParte($parte);
        }

        $doCnj = [];
        foreach ($processo->getMovimentacoes()->toArray() as $movimentacao) {
            $processo->removeMovimentacao($movimentacao);
            $doCnj[self::chaveDaMovimentacao($movimentacao->getDataMovimentacao()?->format('Y-m-d') ?? '', $movimentacao->getDescricao(), $movimentacao->getTipo() ?? '', $movimentacao->getOrgao() ?? '')] ??= $movimentacao;
        }

        return $doCnj;
    }

    /** @param array<string, MovimentacaoProcesso> $movimentacoesDoCnj */
    private function aplicarDadosDaJanela(Processo $processo, CadastroDoProcessoInput $input, string $numero, Tenant $tenant, array $movimentacoesDoCnj): void
    {
        $processo->setNumeroProcesso($numero);
        $this->aplicarTribunal($processo, $numero);
        $processo->setOrgaoJulgador(self::limitado($input->orgaoJulgador, 255, 'Órgão julgador'));
        $processo->setClasseProcessual(self::limitado($input->classeProcessual, 255, 'Classe processual'));
        $processo->setAssuntoProcessual(self::limitado($input->assuntoProcessual, 255, 'Assunto processual'));
        $processo->setSituacaoProcesso(self::opcao($input->situacaoProcesso, self::SITUACOES, 'Situação do processo inválida.'));
        $processo->setInstancia(self::opcao($input->instancia, self::INSTANCIAS, 'Instância inválida.'));
        $this->aplicarDatas($processo, $input);
        $this->aplicarPartes($processo, $input->partes, $tenant);
        $this->aplicarMovimentacoes($processo, $input->movimentacoes, $tenant, $movimentacoesDoCnj);
    }

    /** Sigla derivada do número (padrão CNJ); número de tribunal desconhecido deixa a sigla vazia. */
    private function aplicarTribunal(Processo $processo, string $numero): void
    {
        try {
            $processo->setSiglaTribunal($this->tribunalResolver->resolverSigla($numero));
        } catch (TribunalNaoIdentificadoException) {
            $processo->setSiglaTribunal('');
        }
    }

    private function aplicarDatas(Processo $processo, CadastroDoProcessoInput $input): void
    {
        $distribuicao = self::data($input->dataDistribuicao, 'Data de distribuição inválida.');
        $baixa = self::data($input->dataBaixa, 'Data de baixa inválida.');
        // A baixa que fica: a digitada ou, sem ela, a do CNJ (a janela não tem o campo).
        $baixaFinal = $baixa ?? $processo->getDataBaixa();
        if ($distribuicao !== null && $baixaFinal !== null && $baixaFinal < $distribuicao) {
            throw new DadosDoProcessoInvalidosException('A data de baixa não pode ser anterior à distribuição.');
        }

        $processo->setDataDistribuicao($distribuicao);
        // A janela não tem a data de baixa: vazia, fica a do CNJ (ou nenhuma).
        if ($baixa !== null) {
            $processo->setDataBaixa($baixa);
        }
    }

    /** @param list<array<string, string>> $linhas */
    private function aplicarPartes(Processo $processo, array $linhas, Tenant $tenant): void
    {
        foreach ($linhas as $linha) {
            if (($linha['nome'] ?? '') === '') {
                continue;
            }
            $parte = new ParteProcesso();
            $parte->setTipo(self::opcao($linha['tipo'] ?? '', self::TIPOS_DE_PARTE, 'Tipo de parte inválido.'));
            $parte->setNome(self::limitado($linha['nome'], 255, 'Nome da parte'));
            $parte->setDocumento(self::opcional($linha['documento'] ?? '', 30, 'Documento da parte'));
            $parte->setPapel(self::opcional($linha['papel'] ?? '', 30, 'Papel da parte'));
            $parte->setTenant($tenant);
            $processo->addParte($parte);
        }
    }

    /**
     * @param list<array<string, string>>        $linhas
     * @param array<string, MovimentacaoProcesso> $doCnj a movimentação que veio igual do CNJ é reaproveitada
     */
    private function aplicarMovimentacoes(Processo $processo, array $linhas, Tenant $tenant, array $doCnj): void
    {
        foreach ($linhas as $linha) {
            if (($linha['descricao'] ?? '') === '') {
                continue;
            }
            $chave = self::chaveDaMovimentacao($linha['dataMovimentacao'] ?? '', $linha['descricao'], $linha['tipo'] ?? '', $linha['orgao'] ?? '');
            $movimentacao = $doCnj[$chave] ?? new MovimentacaoProcesso();
            unset($doCnj[$chave]);
            $movimentacao->setDescricao(self::limitado($linha['descricao'], 255, 'Descrição da movimentação'));
            $movimentacao->setTipo(self::opcional($linha['tipo'] ?? '', 100, 'Tipo da movimentação'));
            $movimentacao->setOrgao(self::opcional($linha['orgao'] ?? '', 100, 'Órgão da movimentação'));
            $movimentacao->setDataMovimentacao(self::data($linha['dataMovimentacao'] ?? '', 'Data de movimentação inválida.'));
            $movimentacao->setTenant($tenant);
            $processo->addMovimentacao($movimentacao);
        }
    }

    /** Sem diferença de caixa: a entidade guarda os textos em maiúsculas e a janela pode mostrar de outro jeito. */
    private static function chaveDaMovimentacao(string $data, string $descricao, string $tipo, string $orgao): string
    {
        return mb_strtoupper(implode("\x1F", [$data, trim($descricao), trim($tipo), trim($orgao)]));
    }

    /** @param list<string> $opcoes a primeira é o padrão do campo vazio */
    private static function opcao(string $valor, array $opcoes, string $erro): string
    {
        if ($valor === '') {
            return $opcoes[0];
        }
        if (!in_array($valor, $opcoes, true)) {
            throw new DadosDoProcessoInvalidosException($erro);
        }

        return $valor;
    }

    private static function limitado(string $valor, int $limite, string $rotulo): string
    {
        if (mb_strlen($valor) > $limite) {
            throw new DadosDoProcessoInvalidosException($rotulo . ' passa de ' . $limite . ' caracteres.');
        }

        return $valor;
    }

    private static function opcional(string $valor, int $limite, string $rotulo): ?string
    {
        return $valor === '' ? null : self::limitado($valor, $limite, $rotulo);
    }

    /** `\DateTime` mutável: as colunas são do tipo `date` do Doctrine, que recusa o imutável. */
    private static function data(string $valor, string $erro): ?\DateTime
    {
        if ($valor === '') {
            return null;
        }
        $data = \DateTime::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new DadosDoProcessoInvalidosException($erro);
        }

        return $data;
    }
}

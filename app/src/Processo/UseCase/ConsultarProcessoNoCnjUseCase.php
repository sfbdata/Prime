<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\ConsultaAoCnjOutput;
use App\Processo\Entity\AssuntoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\ConsultasDoCnjGuardadas;
use App\Processo\Service\DatajudClient;
use App\Processo\Service\DatajudProcessoMapper;
use App\Processo\Service\TribunalCnjResolver;
use App\Processo\Service\ValidadorNumeroCnj;
use Psr\Log\LoggerInterface;

/**
 * Consulta um processo na base pública do CNJ (Datajud) pelo número e devolve os dados mapeados, sem gravar nada no
 * banco. É a leitura que a tela ("Buscar no CNJ", `api_datajud_search` e `pasta_processo_buscar_cnj`) e o conector
 * (`ler_processos`, ação `cnj`) compartilham. A permissão de quem consulta é de quem chama.
 *
 * Só o número vai ao CNJ (consulta pública). Quando recebe a sessão, guarda o `_source` no servidor por 30 minutos
 * ({@see ConsultasDoCnjGuardadas}) para o cadastro pela janela da pasta aplicar o que a resposta não leva de volta;
 * sem sessão (o conector), não guarda nada.
 */
final class ConsultarProcessoNoCnjUseCase
{
    /**
     * Acima desta quantidade de processos mais novos do mesmo foro já publicados, o atraso da base
     * não explica a ausência. Medido em 15/10/2026: processo sigiloso de vara de família com 466
     * posteriores, arquivado com 1.187, recente com 0. Perto do corte de publicação a base fica
     * com buracos de poucos processos (um caso real tinha 1), daí a margem.
     */
    private const POSTERIORES_PARA_DESCARTAR_ATRASO = 50;

    public function __construct(
        private readonly DatajudClient $datajud,
        private readonly DatajudProcessoMapper $mapper,
        private readonly ValidadorNumeroCnj $validador,
        private readonly ConsultasDoCnjGuardadas $consultasGuardadas,
        private readonly TribunalCnjResolver $tribunalResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string  $numeroInformado o número como veio (com ou sem pontuação)
     * @param ?string $sessaoParaGuardar o id da sessão que guarda a consulta; nulo: não guarda
     */
    public function executar(string $numeroInformado, ?Tenant $escritorio, User $pessoa, ?string $sessaoParaGuardar = null): ConsultaAoCnjOutput
    {
        $numero = (string) preg_replace('/\D+/', '', $numeroInformado);
        if ($numero === '' || $numero === '0') {
            return ConsultaAoCnjOutput::numeroInvalido('Informe o número do processo para consultar no CNJ.', 400);
        }

        try {
            return $this->consultar($numero, $escritorio, $pessoa, $sessaoParaGuardar);
        } catch (TribunalNaoIdentificadoException) {
            // Número fora do padrão CNJ ou tribunal desconhecido: erro do dado de entrada.
            return ConsultaAoCnjOutput::numeroInvalido($this->mensagemNumeroInvalido($numero), 422);
        } catch (ConsultaDatajudException $e) {
            return $this->falhaDoCnj($e, $numero);
        } catch (\Exception $e) {
            // Erro interno inesperado (ex.: mapeamento): não culpa o CNJ; loga a causa real.
            $this->logger->error('Erro inesperado na consulta ao Datajud', ['exception' => $e]);

            return ConsultaAoCnjOutput::falha('Não conseguimos concluir a consulta agora. Tente novamente; se persistir, avise o suporte.', 500);
        }
    }

    private function consultar(string $numero, ?Tenant $escritorio, User $pessoa, ?string $sessaoParaGuardar): ConsultaAoCnjOutput
    {
        $hits = $this->datajud->searchByNumeroProcesso($numero)['hits']['hits'] ?? null;
        if (!\is_array($hits)) {
            $motivo = MotivoFalhaDatajud::RespostaInvalida;

            return ConsultaAoCnjOutput::falha($motivo->mensagemUsuario(), $motivo->statusHttp(), $motivo);
        }
        if ($hits === []) {
            return ConsultaAoCnjOutput::naoEncontrado($this->mensagemNaoEncontrado($numero));
        }

        $source = $hits[0]['_source'] ?? [];
        if ($escritorio !== null && $sessaoParaGuardar !== null && \is_array($source)) {
            $this->consultasGuardadas->guardar($escritorio, $pessoa, $sessaoParaGuardar, $numero, $source);
        }

        return ConsultaAoCnjOutput::achou($this->dadosMapeados($numero, \is_array($source) ? $source : []));
    }

    /**
     * Mapeia num processo temporário (nunca persistido) e monta os campos no formato da tela.
     *
     * @param array<mixed> $source
     *
     * @return array<string, mixed>
     */
    private function dadosMapeados(string $numero, array $source): array
    {
        $processo = $this->mapper->mapFromSource(new Processo(), $source);

        return [
            ...$this->camposDoCartao($processo, $numero),
            ...$this->listasDoProcesso($processo),
        ];
    }

    /** @return array<string, mixed> */
    private function camposDoCartao(Processo $processo, string $numero): array
    {
        return [
            'numeroProcesso' => $processo->getNumeroProcesso(),
            'orgaoJulgador' => $processo->getOrgaoJulgador(),
            // Sigla derivada do número (mesma fonte usada na persistência), garantindo
            // consistência com o que será salvo mesmo se a resposta do CNJ omitir o tribunal.
            'siglaTribunal' => $this->tribunalResolver->resolverSigla($numero),
            'classeProcessual' => $processo->getClasseProcessual(),
            'assuntoProcessual' => $processo->getAssuntoProcessual(),
            'situacaoProcesso' => $processo->getSituacaoProcesso(),
            'instancia' => $processo->getInstancia(),
            'dataDistribuicao' => $processo->getDataDistribuicao()?->format('Y-m-d'),
            'dataBaixa' => $processo->getDataBaixa()?->format('Y-m-d'),
            'nivelSigilo' => $processo->getNivelSigilo(),
            'nivelSigiloLabel' => $processo->getNivelSigiloLabel(),
            'formato' => $processo->getFormato(),
            'formatoCodigo' => $processo->getFormatoCodigo(),
            'sistema' => $processo->getSistema(),
            'sistemaCodigo' => $processo->getSistemaCodigo(),
            'classeCodigo' => $processo->getClasseCodigo(),
            'orgaoJulgadorCodigo' => $processo->getOrgaoJulgadorCodigo(),
            'orgaoJulgadorMunicipioIbge' => $processo->getOrgaoJulgadorMunicipioIbge(),
            'datajudId' => $processo->getDatajudId(),
        ];
    }

    /** @return array<string, mixed> */
    private function listasDoProcesso(Processo $processo): array
    {
        return [
            'assuntos' => array_map(static fn (AssuntoProcesso $a): array => [
                'codigo' => $a->getCodigo(),
                'nome' => $a->getNome(),
            ], $processo->getAssuntos()->toArray()),
            'partes' => array_map(static fn (ParteProcesso $p): array => [
                'tipo' => $p->getTipo(),
                'nome' => $p->getNome(),
                'documento' => $p->getDocumento(),
                'papel' => $p->getPapel(),
            ], $processo->getPartes()->toArray()),
            'movimentacoes' => array_map(static fn (MovimentacaoProcesso $m): array => [
                'descricao' => $m->getDescricao(),
                'tipo' => $m->getTipo(),
                'orgao' => $m->getOrgao(),
                'dataMovimentacao' => $m->getDataMovimentacao()?->format('Y-m-d'),
            ], $processo->getMovimentacoes()->toArray()),
        ];
    }

    /** Falha na interação com o CNJ (rede, timeout, indisponibilidade, limite, etc.). A mensagem à pessoa é vaga de propósito; a causa técnica vai ao log. */
    private function falhaDoCnj(ConsultaDatajudException $e, string $numero): ConsultaAoCnjOutput
    {
        $motivo = $e->getMotivo();
        $this->logger->warning('Consulta ao Datajud falhou: {motivo}', [
            'motivo' => $motivo->value,
            'exception' => $e,
        ]);

        return ConsultaAoCnjOutput::falha($motivo->mensagemUsuario($this->derivarSiglaSegura($numero)), $motivo->statusHttp(), $motivo);
    }

    /**
     * Mensagem para "o CNJ respondeu, mas sem nenhum resultado".
     *
     * Zero resultados tem duas causas muito diferentes. O dígito verificador (Res. CNJ 65/2008) separa os casos
     * sem custo nenhum: DV não confere é erro de digitação; DV confere é número que existe, mas a base pública do
     * CNJ (publicada com semanas de atraso e sem segredo de justiça) não tem. O DV só escolhe o texto: número com
     * DV errado continua sendo consultado no CNJ normalmente.
     */
    private function mensagemNaoEncontrado(string $numero): string
    {
        $sigla = $this->derivarSiglaSegura($numero);
        if (!$this->validador->digitoVerificadorConfere($numero)) {
            return $this->mensagemDeDigitoVerificadorErrado($sigla);
        }

        return $this->mensagemDeNumeroValido($sigla !== null ? sprintf('na base pública do %s', $sigla) : 'na base pública do CNJ', $this->contarPosteriores($numero));
    }

    private function mensagemDeDigitoVerificadorErrado(?string $sigla): string
    {
        $abertura = $sigla !== null
            ? sprintf('Consultamos a base do %s e não encontramos nenhum processo com esse número.', $sigla)
            : 'Não encontramos nenhum processo com esse número.';

        return $abertura . ' Confira o número digitado: o dígito verificador não confere, o que costuma indicar erro de digitação. Se o número estiver certo, preencha os dados manualmente.';
    }

    /** Segunda consulta só para escolher a explicação: se falhar, nulo, e a mensagem cobre as duas causas. */
    private function contarPosteriores(string $numero): ?int
    {
        try {
            return $this->datajud->contarPosterioresNoForo($numero, self::POSTERIORES_PARA_DESCARTAR_ATRASO);
        } catch (ConsultaDatajudException $e) {
            $this->logger->warning('Falha ao contar processos posteriores no Datajud', ['erro' => $e->getMessage()]);

            return null;
        }
    }

    private function mensagemDeNumeroValido(string $base, ?int $posteriores): string
    {
        if ($posteriores === 0) {
            return sprintf('O número é válido, mas este processo ainda não aparece %s.', $base)
                . ' A base do CNJ é publicada com algumas semanas de atraso e ainda não chegou aos processos mais recentes deste foro.'
                . ' Para cadastrar agora, preencha os dados manualmente. Se preferir, tente a busca de novo daqui a algumas semanas.';
        }

        if ($posteriores !== null && $posteriores >= self::POSTERIORES_PARA_DESCARTAR_ATRASO) {
            return sprintf('O número é válido, mas este processo não aparece %s, embora processos mais novos do mesmo foro já apareçam.', $base)
                . ' Isso acontece com processos em segredo de justiça (como os de família) e com processos arquivados, que a base pública não divulga.'
                . ' Ele não vai aparecer na busca: preencha os dados manualmente.';
        }

        return sprintf('O número é válido, mas não encontramos este processo %s.', $base)
            . ' Isso acontece com processos recentes, que o CNJ publica com algumas semanas de atraso, e com processos em segredo de justiça ou arquivados, que a base pública não divulga.'
            . ' Preencha os dados manualmente.';
    }

    /** Sigla do tribunal sem quebrar quando o número é inválido: só enriquece mensagens de erro. */
    private function derivarSiglaSegura(string $numero): ?string
    {
        try {
            return $this->tribunalResolver->resolverSigla($numero);
        } catch (TribunalNaoIdentificadoException) {
            return null;
        }
    }

    private function mensagemNumeroInvalido(string $numeroDigitos): string
    {
        $qtd = \strlen($numeroDigitos);
        if ($qtd !== 20) {
            return sprintf('O número informado tem %d dígito(s), mas o padrão do CNJ tem 20. Confira e tente de novo.', $qtd);
        }

        return 'Não reconhecemos o tribunal a partir desse número. Confira se o número está correto.';
    }
}

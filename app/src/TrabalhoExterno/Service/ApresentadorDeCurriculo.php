<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Enum\SituacaoAcademica;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O currículo no recorte de quem lê, no contrato JSON da spec VS 18.10.26 §4.5, com os textos do
 * desenho 06 (`cvItem`, l.999; tabela, l.199 a 210; detalhe `tmCv`, l.301 a 312). A nota interna só
 * sai para o interno (`nota: null` para o externo). O termo de voluntariado é o do termo mais recente (`sem`, `enviado`,
 * `aceito`); o resumo dele, sem CPF nem RG, só vai a quem pode ver o termo completo ({@see PoliticaDoTermo}).
 * O dono externo de uma vaga lê o candidato enviado a ele pelo recorte {@see doDonoDaVaga()}.
 */
final class ApresentadorDeCurriculo
{
    private const VOLUNTARIO = [
        'sim' => 'Aceita trabalho voluntário',
        'talvez' => 'Talvez aceite voluntário',
        'nao' => 'Não aceita voluntário',
    ];

    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly CalculadoraDeImportanciaDoCurriculo $importancia,
        private readonly PercentualDoCurriculo $percentual,
        private readonly UrlGeneratorInterface $urls,
        private readonly PoliticaDoTermo $politicaDoTermo,
        private readonly ApresentadorDeTermo $apresentadorDeTermo,
    ) {
    }

    /** A linha do banco de talentos (`cvs[]`). @return array<string, mixed> */
    public function cartao(Curriculo $cv, ?MarcacaoDoUsuario $marca, \DateTimeImmutable $agora, int $candidaturas = 0, ?TermoDeVoluntariado $termo = null, bool $comTermo = true): array
    {
        $sit = $cv->getSituacao();

        return [
            'codigo' => $cv->getCodigo(),
            'nome' => $cv->texto('nome'),
            'iniciais' => self::iniciais($cv->texto('nome')),
            'fotoUrl' => $this->urlDoArquivo($cv, TipoArquivoDoCurriculo::Foto),
            'cargo' => $cv->texto('cargo'),
            'situacao' => $sit->value,
            'situacaoRotulo' => $sit->rotuloCurto(),
            'oab' => $cv->texto('oabNum') !== '' ? $this->numeroDaOab($cv) : 'Sem OAB',
            // Crus, para a busca da tela bater com a do CSV (`FiltroDeCurriculos`).
            'oabNum' => $cv->texto('oabNum'),
            'oabUf' => $cv->texto('oabUf'),
            'cidade' => $cv->texto('cidade'),
            'areas' => $cv->getAreas(),
            'local' => self::local($cv),
            'uf' => $cv->texto('uf'),
            'recebido' => $cv->getRecebidoEm()->format('d/m/Y'),
            'recebidoEm' => $cv->getRecebidoEm()->format(\DATE_ATOM),
            'status' => $cv->getStatus()->value,
            'statusRotulo' => $cv->getStatus()->rotulo(),
            'statusCor' => $cv->getStatus()->cor(),
            'vol' => $cv->texto('vol'),
            'voluntario' => $cv->texto('vol') === 'sim',
            'estrela' => $marca?->temEstrela() ?? false,
            'lixeira' => $marca?->naLixeira() ?? false,
            'vista' => $marca?->isVista() ?? false,
            'importancia' => $this->importancia->calcular($cv, $marca?->getImportante(), $agora, $candidaturas),
            'candidaturas' => $candidaturas,
        ] + ($comTermo ? ['termo' => ApresentadorDeTermo::estado($termo)] : []);
    }

    /**
     * O detalhe (`curriculo`), com o que cada um pode fazer. `$vagas` são os títulos das vagas a que o
     * currículo se candidatou ("Candidaturas:", `cvItem.cands`, l.312).
     *
     * @param list<string> $vagas
     *
     * @return array<string, mixed>
     */
    public function detalhe(Contexto $ctx, Curriculo $cv, ?MarcacaoDoUsuario $marca, \DateTimeImmutable $agora, array $vagas = [], ?TermoDeVoluntariado $termo = null): array
    {
        $comTermo = $ctx->configuracao->termoLigado();

        return $this->cartao($cv, $marca, $agora, count($vagas), $termo, $comTermo) + $this->contato($cv) + ($comTermo ? $this->doTermo($ctx, $cv, $termo) : []) + [
            'exps' => $cv->itens('exps'),
            'forms' => $cv->itens('forms'),
            'idis' => $cv->itens('idis'),
            'arquivos' => $this->arquivos($cv),
            'nota' => $this->politica->veNota($ctx) ? $cv->getNota() : null,
            'situacaoLonga' => $cv->getSituacao()->rotuloLongo(),
            'oabTxt' => $this->textoDaOab($cv),
            'volTxt' => self::VOLUNTARIO[$cv->texto('vol')] ?? 'Voluntário: não informado',
            'experienciaTxt' => ($cv->texto('cargo') !== '' ? $cv->texto('cargo') : 'Cargo não informado') . ' · ' . self::experiencia($cv),
            'atualizado' => $cv->getAtualizadoEm()->format('d/m/Y'),
            'atualizadoEm' => $cv->getAtualizadoEm()->format(\DATE_ATOM),
            'percentual' => $this->percentual->calcular($cv),
            'podeEditar' => $this->politica->podeEditar($ctx, $cv),
            'podeGerir' => $this->politica->podeGerir($ctx),
            'podeExcluir' => $this->politica->podeExcluir($ctx, $cv),
            'documentoUrl' => $this->urls->generate('trabalho_externo_curriculo_documento', ['codigo' => $cv->getCodigo()]),
            'pdfUrl' => $this->urls->generate('trabalho_externo_curriculo_pdf', ['codigo' => $cv->getCodigo()]),
            'candidaturasVagas' => $vagas,
        ];
    }

    /**
     * O candidato que a Central enviou ao dono externo da vaga (decisão 1 da §4.4): o currículo inteiro,
     * sem a nota interna, sem a situação no banco de talentos e sem as candidaturas a outras vagas; nada
     * de marcação nem de ação.
     *
     * @return array<string, mixed>
     */
    public function doDonoDaVaga(Contexto $ctx, Curriculo $cv, \DateTimeImmutable $agora): array
    {
        $semAcao = $ctx->configuracao->termoLigado() ? ['podeGerarTermo' => false, 'termoResumo' => null] : [];

        return array_replace($this->detalhe($ctx, $cv, null, $agora), $semAcao + [
            'nota' => null,
            'status' => null,
            'statusRotulo' => null,
            'statusCor' => null,
            'importancia' => null,
            'candidaturas' => 0,
            'candidaturasVagas' => [],
            'podeEditar' => false,
            'podeGerir' => false,
            'podeExcluir' => false,
        ]);
    }

    /**
     * O botão do termo no detalhe: "Gerar"/"Novo termo" (`podeGerarTermo`, só quem gere, com voluntariado diferente de
     * "não") e o resumo do termo mais recente, para quem pode vê-lo por inteiro.
     *
     * @return array<string, mixed>
     */
    private function doTermo(Contexto $ctx, Curriculo $cv, ?TermoDeVoluntariado $termo): array
    {
        return [
            'podeGerarTermo' => $this->politicaDoTermo->podeGerar($ctx, $cv),
            'termoResumo' => $termo !== null && $this->politicaDoTermo->podeVerCompleto($ctx, $termo) ? $this->apresentadorDeTermo->resumo($ctx, $termo) : null,
        ];
    }

    /** Os campos crus do formulário, para editar e para o detalhe. @return array<string, mixed> */
    private function contato(Curriculo $cv): array
    {
        $campos = ['email', 'tel', 'insta', 'oabNum', 'oabUf', 'inst', 'sem', 'cidade', 'modalidade', 'resumo', 'pretensao', 'tituloP', 'linkedin', 'lattes', 'habs'];
        $dados = [];
        foreach ($campos as $campo) {
            $dados[$campo] = $cv->texto($campo);
        }

        return $dados + ['anos' => $cv->getAnos() !== null ? (string) $cv->getAnos() : ''];
    }

    /** @return array<string, array{nome: string, url: string}|null> */
    private function arquivos(Curriculo $cv): array
    {
        $arquivos = [];
        foreach (TipoArquivoDoCurriculo::cases() as $tipo) {
            $url = $this->urlDoArquivo($cv, $tipo);
            $arquivos[$tipo->value] = $url !== null ? ['nome' => (string) $cv->nomeDoArquivo($tipo), 'url' => $url] : null;
        }

        return $arquivos;
    }

    private function urlDoArquivo(Curriculo $cv, TipoArquivoDoCurriculo $tipo): ?string
    {
        return $cv->temArquivo($tipo)
            ? $this->urls->generate('trabalho_externo_curriculo_baixar', ['codigo' => $cv->getCodigo(), 'tipo' => $tipo->value])
            : null;
    }

    /** "OAB 12345/DF", "OAB estagiário 12345/DF" (`cvItem.oab`). */
    private function numeroDaOab(Curriculo $cv): string
    {
        $uf = $cv->texto('oabUf');

        return ($cv->getSituacao() === SituacaoAcademica::EstOab ? 'OAB estagiário ' : 'OAB ') . $cv->texto('oabNum') . ($uf !== '' ? '/' . $uf : '');
    }

    /** O texto do `cvItem` (l.999): a OAB, ou o curso do estudante, ou "Bacharel(a) sem OAB". */
    private function textoDaOab(Curriculo $cv): string
    {
        return match (true) {
            $cv->texto('oabNum') !== '' => $this->numeroDaOab($cv),
            $cv->getSituacao() === SituacaoAcademica::Est => implode(' · ', array_filter(['Estudante', $cv->texto('inst'), $cv->texto('sem')], static fn (string $v): bool => $v !== '')),
            $cv->getSituacao() === SituacaoAcademica::Bach => 'Bacharel(a) sem OAB',
            default => 'Sem OAB',
        };
    }

    private static function experiencia(Curriculo $cv): string
    {
        return $cv->getAnos() !== null && $cv->getAnos() > 0 ? $cv->getAnos() . ' ano(s) de experiência' : 'Experiência não informada';
    }

    /** "Cidade/UF", ou "Brasil" sem nenhum dos dois (`loc`, l.986). */
    public static function local(Curriculo $cv): string
    {
        $local = implode('/', array_filter([$cv->texto('cidade'), $cv->texto('uf')], static fn (string $v): bool => $v !== ''));

        return $local !== '' ? $local : 'Brasil';
    }

    /** As duas primeiras iniciais do nome (`ini`). */
    public static function iniciais(string $nome): string
    {
        $palavras = array_slice(preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2);

        return $palavras === [] ? '?' : mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), $palavras)));
    }
}

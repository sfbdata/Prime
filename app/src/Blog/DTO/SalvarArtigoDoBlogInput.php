<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Service\Marcadores;
use App\Entity\Auth\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pedido de salvar um artigo do blog (botão "Salvar rascunho" do editor, P2). Sem `id` cria; com `id` atualiza.
 *
 * O rascunho salva incompleto: aqui só vão o formato e os tamanhos das colunas. As regras para ir ao ar (as do zod)
 * moram no {@see \App\Blog\Service\ValidadorDoArtigo}, que roda ao agendar e ao publicar.
 */
final readonly class SalvarArtigoDoBlogInput
{
    /**
     * @param list<array{texto: string, url: string, rotulo: string}> $referencias
     * @param list<string>                                            $relacionados
     */
    public function __construct(
        /** Quem salva: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        #[Assert\NotBlank(message: 'Informe o slug.')]
        #[Assert\Length(max: 120, maxMessage: 'O slug pode ter até {{ limit }} caracteres.')]
        #[Assert\Regex(pattern: ArtigoDoBlog::REGEX_SLUG, message: 'Slug em minúsculas, sem acento, separado por hífen.')]
        public string $slug,
        #[Assert\Choice(callback: [self::class, 'blogsAceitos'], message: 'Escolha em qual blog o artigo sai.')]
        public string $blogs,
        #[Assert\Length(max: 255)] public string $titulo = '',
        #[Assert\Length(max: 255)] public string $tituloCurto = '',
        #[Assert\Length(max: 60, maxMessage: 'O título SEO pode ter até {{ limit }} caracteres.')]
        public string $tituloSeo = '',
        #[Assert\Length(max: 160, maxMessage: 'A meta description pode ter até {{ limit }} caracteres.')]
        public string $descricao = '',
        public string $linhaFina = '',
        public string $resumo = '',
        #[Assert\Length(max: 60)] public string $categoria = '',
        #[Assert\Length(max: 60)] public string $autor = '',
        #[Assert\Length(max: 255)] public ?string $revisorNome = null,
        #[Assert\Length(max: 60)] public ?string $revisorOab = null,
        /** Vazio: o UseCase calcula pelo número de palavras (200 por minuto). */
        #[Assert\Length(max: 20)] public string $leitura = '',
        #[Assert\Length(max: 255)] public ?string $imagemChave = null,
        #[Assert\Length(max: 255)] public ?string $imagemAlt = null,
        #[Assert\Count(max: 30)]
        public array $referencias = [],
        #[Assert\Count(max: 3, maxMessage: 'Escolha até {{ limit }} artigos relacionados.')]
        #[Assert\All([new Assert\Type('string')])]
        public array $relacionados = [],
        public bool $destaque = false,
        /** Etiquetas livres; os limites (8 e 40 caracteres) são conferidos pelo ValidadorDoArtigo e pelo Assert abaixo. */
        #[Assert\Count(max: Marcadores::MAXIMO, maxMessage: 'Use até {{ limit }} marcadores.')]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: Marcadores::TAMANHO_MAXIMO, maxMessage: 'Cada marcador pode ter até {{ limit }} caracteres.')])]
        public array $marcadores = [],
        public string $corpoHtml = '',
        public ?int $id = null,
        /** Trocar o slug de um artigo que já foi ao ar quebra o link antigo: a tela pede confirmação. */
        public bool $confirmarTrocaDeSlug = false,
    ) {
    }

    /** @return list<string> */
    public static function blogsAceitos(): array
    {
        return array_map(static fn (BlogsDoArtigo $b): string => $b->value, BlogsDoArtigo::cases());
    }

    /** @param array<mixed> $corpo o JSON do editor */
    public static function doJson(?User $ator, array $corpo, ?int $id = null): self
    {
        return new self(
            ator: $ator,
            slug: self::texto($corpo, 'slug'),
            blogs: self::texto($corpo, 'blogs'),
            titulo: self::texto($corpo, 'titulo'),
            tituloCurto: self::texto($corpo, 'tituloCurto'),
            tituloSeo: self::texto($corpo, 'tituloSeo'),
            descricao: self::texto($corpo, 'descricao'),
            linhaFina: self::texto($corpo, 'linhaFina'),
            resumo: self::texto($corpo, 'resumo'),
            categoria: self::texto($corpo, 'categoria'),
            autor: self::texto($corpo, 'autor'),
            revisorNome: self::textoOuNulo($corpo, 'revisorNome'),
            revisorOab: self::textoOuNulo($corpo, 'revisorOab'),
            leitura: self::texto($corpo, 'leitura'),
            imagemChave: self::textoOuNulo($corpo, 'imagemChave'),
            imagemAlt: self::textoOuNulo($corpo, 'imagemAlt'),
            referencias: self::referencias($corpo['referencias'] ?? null),
            relacionados: self::relacionados($corpo['relacionados'] ?? null),
            destaque: ($corpo['destaque'] ?? false) === true,
            marcadores: Marcadores::normalizar(\is_array($corpo['marcadores'] ?? null) ? $corpo['marcadores'] : []),
            corpoHtml: \is_string($corpo['corpoHtml'] ?? null) ? $corpo['corpoHtml'] : '',
            id: $id,
            confirmarTrocaDeSlug: ($corpo['confirmarTrocaDeSlug'] ?? false) === true,
        );
    }

    /** @param array<mixed> $corpo */
    private static function texto(array $corpo, string $campo): string
    {
        $valor = $corpo[$campo] ?? '';

        return \is_string($valor) ? trim($valor) : '';
    }

    /** @param array<mixed> $corpo */
    private static function textoOuNulo(array $corpo, string $campo): ?string
    {
        $valor = self::texto($corpo, $campo);

        return $valor === '' ? null : $valor;
    }

    /** @return list<array{texto: string, url: string, rotulo: string}> */
    private static function referencias(mixed $linhas): array
    {
        if (!\is_array($linhas)) {
            return [];
        }

        $saida = [];
        foreach ($linhas as $linha) {
            if (!\is_array($linha)) {
                continue;
            }
            $referencia = ['texto' => self::texto($linha, 'texto'), 'url' => self::texto($linha, 'url'), 'rotulo' => self::texto($linha, 'rotulo')];
            if (implode('', $referencia) !== '') {
                $saida[] = $referencia;
            }
        }

        return $saida;
    }

    /** @return list<string> */
    private static function relacionados(mixed $slugs): array
    {
        if (!\is_array($slugs)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $s): string => \is_string($s) ? trim($s) : '', $slugs), static fn (string $s): bool => $s !== ''));
    }
}

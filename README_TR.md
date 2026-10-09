# Kanonik Adres (canonicalUrl)

[English](README.md)

OJS Services'ın OJS genel eklentisi. Okuyucuya açık her sayfaya tek bir kanonik adres verir, ince sayfaları arama
dizinlerinin dışında tutar ve temiz, önbellekli bir dergi site haritası sunar. Yalnız kancalarla çalışır: çekirdek ya da
tema dosyası değiştirmez, OJS güncellemelerinde kaybolmaz.

| Paket | Hangi OJS için | Denendiği sürümler |
|---|---|---|
| `canonicalUrl-ojs3.3-<sürüm>.tar.gz` | OJS 3.3 | OJS 3.3.0.22; PHP 7.4, 8.1 ve 8.2 |
| `canonicalUrl-ojs3.4-3.5-<sürüm>.tar.gz` | OJS 3.4 ve 3.5 | OJS 3.4.0.3 ve 3.4.0.10, PHP 8.2 (3.4.0.10 ayrıca PHP 8.1); OJS 3.5.0.1 ve 3.5.0.3, PHP 8.2 |

Diğer 3.3.0.x, 3.4.0.x ve 3.5.0.x sürümlerinde de çalışması beklenir, ama denenmedi. İki paket birbirinin yerine
kullanılamaz: OJS 3.3 paketi OJS 3.4 ve 3.5'te yüklenmez; tersi de geçerlidir.

## Site haritası

OJS her dergi için zaten bir site haritası yayımlar. Eklenti kendi haritasını üretmez: OJS'in ürettiği haritayı süzer,
tarih ekler ve önbelleğe alır; böylece arama motorları dizine girmeye değer sayfaların listesini alır.

Adres aynı kalır: `https://<siteniz>/index.php/<dergi>/sitemap`

| OJS'in haritasında | Eklentiyle | Neden |
|---|---|---|
| Makale sayfaları | kalır, `lastmod` ile | |
| Sayı sayfaları | kalır, `lastmod` ile; sayının özel adresi (URL path) varsa o adresle listelenir | kanonik etiketle aynı adres |
| Galley görüntüleme adresleri (`/article/view/N/M`) | çıkarılır | her biri makale sayfasının kopyasıdır; kanonik etiketi makaleyi gösterir |
| Giriş, kayıt, arama | çıkarılır | içerik değil; bu sayfalar `noindex` alır |
| `/issue/current` | çıkarılır | en yeni sayının ikinci adresi |
| Duyurular, özel sayfalar (gezinme menüsü sayfaları) | kalır; iki grup da ayarlardan dışarıda bırakılabilir | |
| Ana sayfa, arşiv, hakkında sayfaları | olduğu gibi kalır | |

![Ayar sayfasının "Sitemap" (Site haritası) sekmesi: harita adresi, önbellek durumu ve harita ayarları](docs/settings-sitemap.png)

- **Tarihler.** OJS `lastmod` yazmaz. Eklenti her makale ve sayıya ekler: yayın tarihi ile son değişiklik tarihinden
  geç olanı; bugünden ileri bir tarih yazılmaz.
- **Örnek.** 80 yayımlanmış makalesi (çoğunda iki galley) ve 10 sayısı olan bir sınama dergisinde OJS'in haritası
  266 adres listeliyordu; eklentiyle 103 adres listeleniyor ve bunların 90'ında `lastmod` var.
- **Önbellek.** OJS haritayı her istekte, makale makale yeniden üretir; büyük dergide bu uzun sürer. Eklenti bitmiş
  haritayı saklar ve sonraki istekleri, OJS yeniden üretmeden, bu kopyadan yanıtlar. Kopya; bir makale ya da sayı
  yayımlandığında, yayından kaldırıldığında veya düzenlendiğinde, eklenti ayarları kaydedildiğinde ve en geç ayarlanan
  süre sonunda (varsayılan 24 saat) yenilenir. Bir istek haritayı yenilerken diğer isteklere önceki kopya verilir.
  Yanıt hangi durumun geçerli olduğunu söyler: `X-CanonicalUrl-Sitemap: hit | miss | stale`. Önbellekten verilen yanıt
  `Cache-Control: public, max-age=3600` ile ve oturum çerezi olmadan gönderilir; `miss` yanıtını OJS kendi başlıklarıyla
  gönderir. Önbellek ayarlardan kapatılabilir.
- **Sizin yapmanız gereken.** Haritayı `robots.txt` dosyanıza ekleyin (eklenti bu dosyayı değiştiremez):
  `Sitemap: https://<siteniz>/index.php/<dergi>/sitemap` ve aynı adresi Google Search Console'da "Site haritaları"
  bölümünden gönderin. Sonrasında harita kendiliğinden güncel kalır.

Süzme, tarihler, duyurular, özel sayfalar ve önbellek ayrı ayarlardır; hepsi varsayılan olarak açıktır. Çok dergili
kurulumların site düzeyindeki harita dizini değiştirilmez.

## Ne yapar

**Kanonik adres**
- Okuyucu sayfalarına `<link rel="canonical">` ekler. Galley görüntüleme sayfaları (`/article/view/N/M`), sayısal kimlik
  ya da özel adres (URL path) ve her türlü sorgu parametresi (`utm_*`, `fbclid`, …) tek makale adresine iner.
- Aynı adresi makale ve galley görüntüleme sayfalarında HTTP `Link: <…>; rel="canonical"` başlığı olarak da gönderir.
- Hata sayfalarına (404, 403) ve dosya indirmelerine (`/article/download/…`) hiçbir zaman kanonik adres eklemez.
- Ana sayfanın tek adresi vardır: `/<dergi>/index` ve `/<dergi>/index/index`, `/<dergi>` adresini gösterir.
- Sayfa başına tek kanonik etiket: tema, başka bir eklenti ya da derginin özel etiketleri zaten kanonik etiket basıyorsa
  eklenti kendi etiketini (iki adres farklıysa `Link` başlığını da) basmaz ve bunu ayar sayfasında bildirir.
- Google Scholar: kanonik adres `citation_abstract_html_url` ile aynı adrestir; özel adres (URL path) ve `restful_urls`
  ile de. Birden çok dili olan OJS 3.5 dergilerinde OJS bu etiketi dil kodu olmadan yazar (`/<dergi>/article/view/N`;
  okuyucunun diline yönlenir); kanonik adres ise okunan dildeki sayfadır (`/<dergi>/<dil>/article/view/N`).
- Eski makale sürümleri (`/article/view/N/version/P`): OJS bu sayfaları kendisi `noindex` yapar ve güncel sürümü gösterir;
  eklenti buraya bir şey eklemez, sayfada tam bir kanonik etiket kalır.
- OJS 3.5 (adreste dil kodu): `hreflang` seçenekleri aynı kanonik adresleri gösterir; `x-default` derginin birincil dilidir.

**İndeksleme dışı**
Şu sayfalar kanonik adres yerine `noindex, follow` alır. Liste sabittir:

| Dergi altındaki adres | Kapsadığı sayfalar | `noindex` nasıl gönderilir |
|---|---|---|
| `search`, `search/…` | arama formu ve tüm sonuç sayfaları | meta etiketi + `X-Robots-Tag` başlığı |
| `login`, `login/…` | giriş, çıkış, şifre unutma ve sıfırlama | meta etiketi + başlık |
| `user`, `user/…` | kayıt, profil, dil değiştirme (`user/setLocale`) ve diğer kullanıcı sayfaları | meta etiketi + başlık |
| `notification`, `notification/…` | bildirimler | meta etiketi + başlık |
| `about/aboutThisPublishingSystem` | "Bu yayın sistemi hakkında" | meta etiketi + başlık |
| `citationstylelanguage/…` | atıf biçimi indirmeleri (makale başına çok sayıda adres) | yalnız başlık |

Bunların dışındaki her sayfa indekslenebilir kalır; sayı arşivi ve devam sayfaları (`issue/archive/2` …) ile duyurular
da öyle. İki ayar, ilk beş satırı ve son satırı toplu olarak açar ya da kapatır.

## Kurulum

1. Web Sitesi Ayarları > Eklentiler > Yeni Bir Eklenti Yükle; OJS sürümünüze uygun paketi seçin.
   Ya da sunucuda:
   ```
   tar xzf canonicalUrl-ojs<hat>-<sürüm>.tar.gz -C plugins/generic/
   php lib/pkp/tools/installPluginVersion.php plugins/generic/canonicalUrl/version.xml
   ```
2. Etkinleştirin: Eklentiler > Genel Eklentiler > Kanonik Adres (Canonical URL).
3. Site haritasını `robots.txt` dosyanıza ekleyin (eklenti bu dosyayı değiştiremez):
   `Sitemap: https://<siteniz>/index.php/<dergi>/sitemap`

Eklentiyi yükseltme: yeni paketi aynı yolla kurun (ya da eklenti listesinde "Güncelle"yi kullanın), sonra OJS önbelleğini
temizleyin (`cache/fc-*.php`, `cache/t_compile/*`; OJS 3.5'te ayrıca `cache/opcache/*`). Ayarlar korunur.

### OJS'i 3.3'ten 3.4 ya da 3.5'e yükseltirken

OJS 3.3 paketi OJS 3.4 ve 3.5'te yüklenmez. OJS yükseltmesinden sonra `plugins/generic/canonicalUrl` klasöründe hâlâ o
paket duruyorsa site çalışmaya devam eder, ama eklenti yüklenmez: sayfalarda kanonik etiket olmaz, site haritası OJS'in
süzülmemiş haritasıdır ve PHP hata günlüğünde eklenti dosyası için `Call to undefined function import()` satırı görünür.

1. OJS'i yükselttikten sonra `canonicalUrl-ojs3.4-3.5` paketini kurun (yükleyin ya da `plugins/generic/` içine açın).
   Eski klasörün üstüne açmak da çalışır; önce eski klasörü silmek, kullanılmayan dosya bırakmaz.
2. OJS önbelleğini yukarıdaki gibi temizleyin.

Eklenti etkin kalır ve ayarları korunur; ayarlar veritabanında aynı eklenti adıyla durur.

## Ayarlar

![Ayar sayfasının "Canonical address" (Kanonik adres) sekmesi: kullanılan ana makine adı ve üç kanonik adres ayarı](docs/settings.png)

Eklentiler > Genel Eklentiler > Kanonik Adres > Ayarlar (Dergi Yöneticisi ya da Site Yöneticisi; ayarlar dergi başınadır).
Her şey varsayılan olarak açıktır; önerilen yapılandırma budur.

| Sekme | Ayar |
|---|---|
| Kanonik adres | Kanonik etiket · galley sayfaları makaleyi göstersin · HTTP `Link` başlığı · kullanılan ana makine adı (bilgi; `base_url`'den farklıysa uyarı) |
| Site haritası | Galley adreslerini çıkar · giriş/kayıt/arama/güncel sayıyı çıkar · `lastmod` ekle · duyuruları dahil et · özel sayfaları dahil et · önbellek ve süresi (1–720 saat) |
| İndeksleme dışı | İnce sayfalara `noindex` · atıf biçimi indirmelerine `noindex` başlığı |

Ayar sayfası iki durumu da bildirir; böylece kimsenin sunucuya bakması gerekmez:
- önbellek klasörüne (`cache/canonicalUrl`) yazılamıyor (harita o zaman her istekte yeniden üretilir);
- derginin sayfalarında başka bir kanonik etiket bulundu (eklenti orada kendi etiketini basmadı).

Çok dergili kurulumlar: ayarlar dergi başınadır. Site geneli sayfaların (site ana sayfası) da kanonik adres alması için
eklentiyi Yönetim > Site Ayarları > Eklentiler'de de etkinleştirin; bu sayfalar varsayılan ayarları kullanır. Ayar
sayfası oradan açıldığında yalnız ana makine adı bilgisini gösterir.

## Notlar

- Önbellek `cache/canonicalUrl/` klasöründedir. Bir değişiklikten sonraki ilk istek, OJS'in haritayı üretme süresi kadar
  sürer; sonraki istekler önbellekten yanıtlanır. Derginin açıldığı her adres (ana makine adı; OJS 3.5'te ayrıca dil)
  için bir önbellek dosyası tutulur; dergi başına en çok 8 dosya (dergi dillerinin iki katı daha fazlaysa o kadar):
  sınır aşılınca en uzun süredir kullanılmayan dosya silinir.
- Eklentinin kendi metinleri (ad, açıklama, ayar sayfası) İngilizce, Türkçe ve İspanyolcadır.
- `robots.txt` ile engellenen bir yol hiç taranmaz; arama motorları o sayfanın `noindex`'ini görmez.
- Ana makine adı: OJS adreslerini, dolayısıyla kanonik adresi, sitenin açıldığı ana makine adıyla üretir
  (`config.inc.php` içinde `base_url[<dergi>]` tanımlıysa onunla). Eklenti ana makine adını değiştirmez. Site iki adla
  açılıyorsa (www'li ve www'siz) her ad kendi kanonik adreslerini alır: web sunucusunda birini diğerine 301 ile
  yönlendirin. Kullanılan ad `base_url`'den farklıysa ayar sayfası uyarır.

## Dış istekler

Yok. Eklenti, ziyaretçinin tarayıcısında ya da sunucuda başka hiçbir sunucudan bir şey yüklemez ve hiçbir yere veri
göndermez. Yalnız site haritası önbelleğini OJS'in `cache/canonicalUrl/` klasörüne yazar.

## Destek

OJS Services tarafından geliştirilmiştir (https://ojs-services.com). Soru ve hata bildirimleri için bu depoda bir issue açın.

## Lisans

GNU Genel Kamu Lisansı v3. Bkz. `LICENSE` dosyası.

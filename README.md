# STB – Order Attachments (Secure)

Bezpieczne załączniki do zamówień WooCommerce + REST API

## Instalacja

### Instalacja ręczna

1. Pobierz paczke zip z pluginem order-attachments-x.x.x.zip
2. Rozpakuj oraz umiesc w wp-content/plugins/
3. Aktywuj plugin w panelu administracyjnym WordPress

### Przez Composer (zalecane)

1. ```bash
    # Klonowanie repozytorium
    git clone https://github.com/STB-Sp-z-o-o/order-attachments/
    ```
2.  Przejście do katalogu pluginu
    ```bash 
    cd order-attachments
    ```
3. Instalacja zależności
    ```bash
    composer install
    ```

Aktywacja pluginu w panelu WordPress




## Użytkowanie

### Panel administracyjny

1. **Edycja zamówienia** - w bocznym panelu pojawi się metabox "Załączniki (STB)"
2. **Dodawanie plików** - użyj pola "Dodaj nowy załącznik" i kliknij "Dodaj"
3. **Zarządzanie** - możesz przeglądać, pobierać i usuwać załączniki
4. **Bezpieczne linki** - wszystkie linki do pobrania są zabezpieczone nonce

### REST API

#### Uwierzytelnianie
API wymaga uwierzytelnienia consumer key i consumer secret z WooCommerce.

#### Endpointy

**Pobranie wszystkich załączników zamówienia**
```http
GET /wp-json/stb/v1/orders/{order_id}/attachments
```

**Dodanie nowego załącznika**
```http
POST /wp-json/stb/v1/orders/{order_id}/attachments
Content-Type: multipart/form-data

# Parametry:
# file - plik do załączenia (wymagane)
```

**Przykład cURL:**
```bash
curl -X POST \
  'https://localhost:7003/wp-json/stb/v1/orders/4393/attachments' \
  -H 'Content-Type: multipart/form-data' \
  -u 'ck_asdasd:cs_asdasd' \
  -F 'file=@/ścieżka/do/pliku.pdf'
```


**Usunięcie załącznika**
```http
DELETE /wp-json/stb/v1/orders/{order_id}/attachments?att_id={attachment_id}
```

### Shortcode dla frontendu

```php
// W szablonie lub treści
[stb_order_attachments order_id="123"]

// Automatyczne wyświetlanie w szczegółach zamówienia
// (włączone domyślnie)
```

### Architektura

```php
STB\OrderAttachments\
├── Plugin          # Główny orchestrator
├── AdminPage       # UI administracyjne
├── Repository      # Warstwa danych
├── RestController  # API REST
├── Installer       # Instalacja/aktywacja
└── Shortcodes      # Frontend
```

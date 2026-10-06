# Bếp Nhà — website đặt món tại Nha Trang

Phiên bản thực hành **chỉ chạy localhost**, cập nhật ngày 23/09/2026. Laravel 13, PHP 8.5, Blade MVC, Eloquent, MySQL 8.4 và Tailwind CSS standalone. Không dùng Node.js, npm, Vite, Vue hay React. CSS đã có trong `public/css/app.css`.

Khách đặt tại bàn, mang về hoặc giao trong Nha Trang; dùng tài khoản hoặc đặt vãng lai bằng email **hoặc** số điện thoại. Giao diện có đúng hai ngôn ngữ VI/EN. Thanh toán gồm tiền mặt và thẻ mô phỏng; email thử nằm trong Mailpit. Không cần Stripe key, SMTP bên ngoài, hosting hay tên miền.

## Khởi động và dừng

Máy cần Docker Desktop đang chạy; PHP 8.5 và Composer để cài thư viện lần đầu.

```sh
composer install
# Chỉ máy mới chưa có .env:
cp .env.example .env
# Mở .env và tự đặt DEMO_STAFF_PASSWORD (ít nhất 12 ký tự).
php artisan key:generate
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan storage:link
```

Với máy đã có `.env` và dữ liệu: chỉ chạy `./vendor/bin/sail up -d`, rồi `./vendor/bin/sail artisan migrate`. Không sao chép đè `.env`, tạo lại APP_KEY hoặc dùng `migrate:fresh` với database đang sử dụng. Seeder không đổi mật khẩu, giá món hay trạng thái đơn đã tồn tại.

| Dịch vụ | Địa chỉ local |
|---|---|
| Trang khách | http://localhost:8000 |
| Đăng nhập khách | http://localhost:8000/tai-khoan/dang-nhap |
| Đăng nhập nội bộ riêng | http://localhost:8000/nhan-vien/dang-nhap |
| Adminer | http://localhost:8080 |
| Hộp thư Mailpit | http://localhost:8025 |
| MySQL từ máy chủ | 127.0.0.1:3307 |

Adminer: chọn MySQL, server `mysql`; database/user/password theo `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` trong `.env`. Các cổng Docker chỉ bind `127.0.0.1`.

```sh
./vendor/bin/sail down       # Giữ dữ liệu trong volume; không thêm -v.
./vendor/bin/sail ps
./vendor/bin/sail artisan config:clear   # Sau khi sửa .env.
```

Mailpit dùng `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, không username/password. Thư không được gửi ra hộp thư ngoài. Quên mật khẩu khách tạo link dùng một lần, hết hạn sau 60 phút. Link này là dữ liệu riêng trong hộp thư local.

## Vai trò và tài khoản mẫu

Mật khẩu tài khoản mới lấy từ `DEMO_STAFF_PASSWORD`. Seeder chỉ tạo tài khoản mẫu trong local/testing; không đặt lại tài khoản đã sửa.

| Vai trò | Email mẫu | Công việc |
|---|---|---|
| Chủ nhà hàng (`admin`) | admin@bepnha.test | Toàn quyền; doanh thu, cấu hình, hoàn tiền, nhật ký và quản lý tài khoản |
| Quản lý (`manager`) | operations@bepnha.test | Vận hành, món, khách, nhân viên; không cấp quyền chủ nhà hàng |
| Thu ngân (`cashier`) | cashier@bepnha.test | Nhận đơn, thu tiền, nhận tiền COD và chốt ca |
| Bếp trưởng (`head_chef`) | head_chef@bepnha.test | Điều phối bếp, phân công đầu bếp/phụ bếp |
| Đầu bếp (`chef`) | chef@bepnha.test | Chuẩn bị và cập nhật món được phân công |
| Phụ bếp (`kitchen_assistant`) | kitchen_assistant@bepnha.test | Nhận và hoàn tất việc sơ chế |
| Phục vụ (`waiter`) | waiter@bepnha.test | Tạo đơn tại bàn, gọi thêm, bưng món được phân công |
| Giao hàng (`courier`) | courier@bepnha.test | Nhận đơn được giao, xác nhận giao và thu COD |
| Khách hàng | customer.vi@bepnha.test, customer.en@bepnha.test | Hồ sơ, địa chỉ mặc định, lịch sử đơn chính chủ |

Nếu nâng từ phiên bản cũ, `manager@bepnha.test` là tài khoản chủ nhà hàng đã được migration đổi vai trò; `kitchen@bepnha.test` là tài khoản bếp trưởng cũ. Không thay mật khẩu cũ.

Trang khách không hiển thị đường đăng nhập nhân viên. Hai khu vực dùng layout và route riêng; quyền luôn kiểm tra phía server, không dựa vào việc ẩn nút. Nhân viên bếp không thấy thông tin liên hệ của khách.

## Chức năng hiện có

- Thực đơn từ MySQL: tìm Việt/Anh, lọc nhóm, ảnh, giá, còn món, chay/cay/dị ứng. CRUD món, nhóm, vùng giao và ảnh; giữ tên/giá lịch sử trong đơn.
- Giỏ hàng session; xác nhận đơn hai bước; server tính tổng/phí; transaction và khóa chống gửi trùng. Một bàn chỉ có một đơn đang hoạt động.
- Nha Trang là khu vực duy nhất. Khách chọn phường và địa chỉ; nhân viên xác nhận địa chỉ trước khi nhận/giao đơn. Đây là kiểm tra thủ công, không phải định vị bản đồ tự động.
- Tra cứu khách vãng lai bằng mã đơn và đúng email/điện thoại; đơn tài khoản chỉ cho chính khách đăng nhập xem. Có phiếu in, yêu cầu hủy và lịch sử trạng thái.
- POS, bàn ăn, gọi thêm món, sửa số lượng/hủy món theo trạng thái, chuyển bàn, gộp và tách đơn tiền mặt đủ điều kiện. Chưa có đặt bàn theo khung giờ.
- Bếp cập nhật từng món; phân việc sơ chế/nấu/phục vụ/giao hàng. Danh sách đơn và màn hình bếp tự kiểm tra thay đổi mỗi 15 giây; âm báo cần người dùng bật trên trình duyệt.
- Giao COD tách bước nhân viên giao thu tiền và thu ngân nhận tiền. Chốt ca kiểm tra danh sách đơn chưa chốt và yêu cầu lý do nếu lệch tiền.
- Chủ nhà hàng xem doanh thu, CSV doanh thu/ca và nhật ký thao tác; tách dữ liệu mẫu/thật, không cộng đơn hủy/hoàn tiền vào doanh thu hợp lệ.
- Quản lý tài khoản nhân viên/khách, khóa/mở, sửa hồ sơ; khóa tài khoản thu hồi phiên. Khách không thể tự nâng quyền qua form.
- Footer có thương hiệu, thông tin liên hệ và các mục chính sách song ngữ. Địa chỉ/điện thoại seed là minh họa, không phải thông tin một cơ sở thật.

Session lưu trong database; cookie phiên được mã hóa, HttpOnly, SameSite=Lax. Đổi ID phiên khi đăng nhập, hủy phiên khi đăng xuất. Cookie ngôn ngữ chỉ nhận VI/EN. Các form có CSRF, validation, giới hạn tần suất; không lưu PAN/CVC trong database, session hay nhật ký ứng dụng.

## Thanh toán mô phỏng

Chọn **Thẻ mô phỏng** khi đặt đơn, mở trang thanh toán rồi nhập:

- Tên chủ thẻ, email hợp lệ, địa chỉ mẫu.
- Số thử `4242 4242 4242 4242`; hạn MM/YY trong tương lai; CVC 3–4 số.
- Quốc gia VN và mã bưu chính 5 số, ví dụ `65000`.

Dữ liệu phải đúng định dạng và số thẻ phải qua kiểm tra Luhn. Hợp lệ thì luôn thành công, không mô phỏng ngân hàng từ chối, không trừ tiền thật. Gửi trùng không tạo hai giao dịch. Đơn thẻ mô phỏng được đánh dấu `is_demo`; chủ nhà hàng có thể hoàn tiền mô phỏng sau khi hủy đơn. Chỉ dùng số thẻ thử. Mã tích hợp Stripe Test cũ còn trong dự án để tương thích, không cần bật profile `stripe` hoặc điền key cho bản local này.

## Dữ liệu và kịch bản trình diễn

Database mới seed 18 món, 6 nhóm, 8 bàn B01–B08, 1 vùng giao Nha Trang và 52 đơn có chi tiết/lịch sử. Hai tài khoản khách có đơn chính chủ. Đơn có `is_demo=1` và khóa seed ổn định; seed lặp không nhân đôi. Các đơn lịch sử cũ chưa có `completed_by` không tự đưa vào ca mới. Database đang sử dụng có thể có nhiều hơn 52 đơn do thao tác thử.

1. **Khách vãng lai:** chuyển VI/EN, tìm món, thêm giỏ, đặt mang về bằng email (bỏ trống điện thoại). Ghi mã đơn, tra cứu bằng email đúng; email sai phải bị từ chối.
2. **Thẻ mô phỏng:** tạo đơn mới, thử bỏ trống/sai trường để xem validation rồi nhập dữ liệu mẫu ở trên. Kết quả đã thanh toán và một giao dịch duy nhất; gửi yêu cầu hủy, quản lý hủy và chủ nhà hàng hoàn tiền mô phỏng.
3. **Tại bàn:** thu ngân/phục vụ tạo đơn ở bàn trống; quản lý phân phục vụ, bếp trưởng phân đầu bếp và phụ bếp. Phụ bếp hoàn thành việc; đầu bếp đổi từng món chờ làm → đang làm → sẵn sàng; phục vụ bàn giao; thu ngân thu tiền/hoàn tất rồi chốt ca.
4. **Giao Nha Trang:** khách tài khoản đặt bằng điện thoại, chọn phường/địa chỉ. Quản lý xác nhận địa chỉ, nhận đơn và phân giao hàng. Sau khi bếp xong, nhân viên giao chuyển đang giao → đã giao/đã thu COD; thu ngân nhận tiền rồi hoàn tất.
5. **Điều chỉnh:** tạo hai đơn tại hai bàn; thử gọi thêm, sửa/hủy món chưa chế biến, chuyển bàn trống, gộp/tách khi đủ điều kiện. Đơn đã thanh toán không được đổi tổng tùy ý.
6. **Phân quyền:** đăng nhập lần lượt các vai trò; khách không vào nội bộ, quản lý không mở doanh thu/cấu hình chủ nhà hàng, bếp chỉ xử lý việc được phân công. Chủ nhà hàng khóa một tài khoản thử và kiểm tra phiên bị thu hồi.
7. **Email:** ở tài khoản khách chọn quên mật khẩu rồi xem thư ở Mailpit. Kiểm tra link/token bằng các bài test tự động; không cần cấu hình SMTP bên ngoài.

Các thao tác demo tạo dữ liệu thật trong MySQL local. Dùng thông tin giả và lọc dữ liệu mẫu khi trình diễn doanh thu.

## Kiểm thử và bằng chứng ngày 23/09/2026

```sh
php artisan test --compact
# Hoặc: ./vendor/bin/sail artisan test --compact
php vendor/bin/pint --format agent
composer css:build
```

Tests dùng SQLite in-memory, không xóa MySQL ứng dụng. Kết quả mới nhất: **116 ca đạt, 1.350 assertions**; xem `storage/app/private/local-final-tests.json`. Bao phủ CRUD, tiền, phân quyền, validation VI/EN, session, XSS, seeding, các luồng liên vai trò và hồi quy.

Kiểm thử ghi đồng thời dùng MySQL riêng, CSRF/session/cache database thật, tối đa 50 client: **229 request**, toàn bộ kiểm tra đạt. 50 đơn và 50 thanh toán có tổng chính xác; gửi trùng chỉ tạo một bản ghi. 20 nhân viên cùng nhận một bàn: chỉ một người thành công, 19 phản hồi 422 đúng dự kiến. Đã sửa lỗi snapshot MySQL khiến nhiều đơn cùng chiếm một bàn bằng locking read trong transaction. Kết quả: `storage/app/private/local-concurrency-20260923-170359.json`.

Kiểm thử đọc: 100 món, 10.000 đơn (1.000 đơn hoạt động), 500 request, 50 client; p95 **1.755,38 ms**, 0 lỗi. Kết quả: `storage/app/private/benchmark-20260923-165348.json`. Đây là số đo trên máy local tại lần chạy đó, không phải cam kết tải trên mọi máy. Các pha ghi có p95 từ 92,32 đến 1.893,49 ms; 403/404/419/422 trong kịch bản bảo vệ là phản hồi mong đợi.

Để chạy lại kiểm thử đồng thời, tạo **database trống riêng** qua Adminer bằng tài khoản root và `DB_PASSWORD`, ví dụ:

```sql
CREATE DATABASE restaurant_acceptance_manual001 CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON restaurant_acceptance_manual001.* TO 'sail'@'%';
```

```sh
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan restaurant:verify-local --database=restaurant_acceptance_manual001
```

Lần chạy sau dùng tên mới. Lệnh từ chối database có dữ liệu, database ứng dụng, tên không đúng prefix hoặc môi trường không phải local. Database kiểm thử được giữ riêng để kiểm tra kết quả. Kiểm thử đọc tương tự với database trống `restaurant_benchmark_manual001` và lệnh `restaurant:benchmark --database=restaurant_benchmark_manual001`. Không thay `DB_DATABASE` của website.

## Sao lưu và khôi phục thử

```sh
./vendor/bin/sail artisan restaurant:backup
sh scripts/restore-drill.sh storage/app/private/backups/<ten-file-vua-tao>.sql
```

Scheduler sao lưu lúc 02:00 Asia/Ho_Chi_Minh **khi Docker đang chạy**. Dump nằm trong `storage/app/private/backups`, quyền file 0600, không phục vụ qua web. Restore drill tạo database mới `restaurant_restore_<timestamp>_<pid>`, không ghi đè database ứng dụng; kết quả nằm trong `storage/app/private/restore-check-*.txt`.

Lần kiểm tra 23/09/2026 khôi phục được 57 đơn, 106 dòng món, 18 món, 12 tài khoản, 1 giao dịch và 229 lịch sử. Tổng đơn 9.600.000 đồng; tổng dòng món 9.235.000 đồng; giao dịch 29.000 đồng. Chênh tổng đơn/dòng món là phí giao. Dump không bao gồm ảnh: lưu thêm thư mục `storage/app/public` và giữ `.env` riêng trên máy. Không nhập dump không rõ nguồn gốc. Chưa tự xóa backup cũ.

## Sửa giao diện không dùng Node

```sh
sh scripts/install-tailwind.sh  # Chỉ khi thiếu standalone CLI.
composer css:build
# composer css:watch
```

Tải lại trình duyệt sau khi sửa Blade/CSS. Báo cáo học phần ở `../Bao_cao_du_an_Website_Dat_Mon_Nha_Hang.docx`; các số WBS/PERT/EVM trong báo cáo là giả định học phần, kết quả kỹ thuật local được ghi riêng. Phạm vi chưa gồm kho nguyên liệu, hóa đơn thuế, đặt bàn theo giờ hay tích hợp giao vận bên ngoài; không có công việc triển khai trong đợt này.

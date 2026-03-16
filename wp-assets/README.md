# WordPress.org SVN Assets

Place your WordPress.org plugin page assets here.
These files are synced to the `/assets/` directory in the SVN repository
and appear on your plugin's WordPress.org page.

## Required Files

| File | Size | Purpose |
|------|------|---------|
| `banner-772x250.png` | 772x250 px | Plugin banner (standard) |
| `banner-1544x500.png` | 1544x500 px | Plugin banner (retina/HiDPI) |
| `icon-128x128.png` | 128x128 px | Plugin icon (standard) |
| `icon-256x256.png` | 256x256 px | Plugin icon (retina/HiDPI) |

## Optional Files

| File | Size | Purpose |
|------|------|---------|
| `screenshot-1.png` | Any | Matches "Screenshots" section #1 in readme.txt |
| `screenshot-2.png` | Any | Matches "Screenshots" section #2 in readme.txt |
| `screenshot-3.png` | Any | ... and so on |
| `banner-772x250.jpg` | 772x250 px | JPG alternative for banner |
| `icon-128x128.jpg` | 128x128 px | JPG alternative for icon |

## Notes

- Use PNG for best quality, JPG is also accepted
- Screenshots are numbered to match the `== Screenshots ==` section in readme.txt
- Run `./deploy.sh --assets` to update only these assets without deploying code
